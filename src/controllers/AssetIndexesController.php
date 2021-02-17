<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\errors\AssetException;
use craft\errors\AssetLogicException;
use craft\errors\UploadFailedException;
use craft\errors\VolumeException;
use craft\fields\Assets as AssetsField;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\helpers\Image;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\i18n\Formatter;
use craft\image\Raster;
use craft\models\AssetIndexingSession;
use craft\models\VolumeFolder;
use craft\web\Controller;
use craft\web\UploadedFile;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;
use ZipArchive;

/** @noinspection ClassOverridesFieldOfSuperClassInspection */

/**
 * The AssetIndexes class is a controller that handles asset indexing tasks.
 * Note that all actions in the controller require an authenticated Craft session as well as the relevant permissions.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class AssetIndexesController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        // No permission no bueno
        $this->requirePermission('utility:asset-indexes');
        $this->requireAcceptsJson();

        return parent::beforeAction($action);
    }

    /**
     * Start an indexing session.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionStartIndexing(): Response
    {
        $request = Craft::$app->getRequest();
        $volumes = (array)$request->getRequiredBodyParam('volumes');
        $cacheRemoteImages = (bool)$request->getBodyParam('cacheImages', false);
        $asQueueJob = (bool)$request->getBodyParam('useQueue', false);

        if (empty($volumes)) {
            return $this->asErrorJson(Craft::t('app', 'No volumes specified'));
        }

        $indexingSession = Craft::$app->getAssetIndexer()->startIndexingSession($volumes, $cacheRemoteImages, $asQueueJob);
        $sessionData = $this->prepareSessionData($indexingSession);

        return $this->asJson(['session' => $sessionData]);
    }

    /**
     * Stop an indexing sessions.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Throwable if something goes wrong.
     */
    public function actionStopIndexingSession(): Response
    {
        $sessionId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sessionId');

        if (empty($sessionId)) {
            return $this->asErrorJson(Craft::t('app', 'No indexing session specified'));
        }

        $session = Craft::$app->getAssetIndexer()->getIndexingSessionById($sessionId);

        if ($session) {
            Craft::$app->getAssetIndexer()->stopIndexingSession($session);
        }

        return $this->asJson(['stop' => $sessionId]);
    }

    /**
     * Progress an indexing session by one step.
     *
     * @return Response
     * @throws \Throwable if something goes wrong
     */
    public function actionProcessIndexingSession(): Response
    {
        $sessionId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sessionId');

        if (empty($sessionId)) {
            return $this->asErrorJson(Craft::t('app', 'No indexing session specified'));
        }

        $assetIndexer = Craft::$app->getAssetIndexer();
        $indexingSession = $assetIndexer->getIndexingSessionById($sessionId);

        // Have to account for the fact that some people might be processing this in parallel
        // If the indexing session no longer exists - most likely a parallel user finished it
        if (!$indexingSession) {
            return $this->asJson(['stop' => $sessionId]);
        }

        $skipDialog = false;

        // If action is not required, continue with indexing
        if (!$indexingSession->actionRequired) {
            $indexingSession = $assetIndexer->processIndexSession($indexingSession);

            // If action is now required, we just processed the last entry
            // To save a round-trip, just pull the session review data
            if ($indexingSession->actionRequired) {
                $indexingSession->skippedEntries = $assetIndexer->getSkippedItemsForSession($indexingSession);
                $indexingSession->missingEntries = $assetIndexer->getMissingEntriesForSession($indexingSession);

                // If nothing out of ordinary, just end it.
                if (empty($indexingSession->skippedEntries) && empty($indexingSession->missingEntries)) {
                    $assetIndexer->stopIndexingSession($indexingSession);
                    return $this->asJson(['stop' => $sessionId]);
                }
            }
        } else {
            $skipDialog = true;
        }

        $sessionData = $this->prepareSessionData($indexingSession);
        return $this->asJson(['session' => $sessionData, 'skipDialog' => $skipDialog]);
    }

    /**
     * Fetch an indexing session overview.
     *
     * @return Response
     * @throws AssetException
     * @throws BadRequestHttpException
     */
    public function actionIndexingSessionOverview(): Response
    {
        $sessionId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sessionId');

        if (empty($sessionId)) {
            return $this->asErrorJson(Craft::t('app', 'No indexing session specified'));
        }

        $assetIndexer = Craft::$app->getAssetIndexer();
        $indexingSession = $assetIndexer->getIndexingSessionById($sessionId);

        if (!$indexingSession || !$indexingSession->actionRequired) {
            return $this->asErrorJson(Craft::t('app', 'Cannot find the indexing session or nothing to review'));
        }

        $indexingSession->skippedEntries = $assetIndexer->getSkippedItemsForSession($indexingSession);
        $indexingSession->missingEntries = $assetIndexer->getMissingEntriesForSession($indexingSession);

        $sessionData = $this->prepareSessionData($indexingSession);
        return $this->asJson(['session' => $sessionData]);
    }

    /**
     * Finish an indexing session, removing the specified file and folder records.
     * @return Response
     * @throws \Throwable
     */
    public function actionFinishIndexingSession(): Response
    {
        $sessionId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sessionId');

        if (empty($sessionId)) {
            return $this->asErrorJson(Craft::t('app', 'No indexing session specified'));
        }

        $session = Craft::$app->getAssetIndexer()->getIndexingSessionById($sessionId);

        if ($session) {
            Craft::$app->getAssetIndexer()->stopIndexingSession($session);
        }

        $deleteFolders = Craft::$app->getRequest()->getBodyParam('deleteFolder', []);
        $deleteFiles = Craft::$app->getRequest()->getBodyParam('deleteAsset', []);

        if (!empty($deleteFolders)) {
            Craft::$app->getAssets()->deleteFoldersByIds($deleteFolders, false);
        }

        if (!empty($deleteFiles)) {
            Craft::$app->getAssetTransforms()->deleteTransformIndexDataByAssetIds($deleteFiles);
            $assets = Asset::find()
                ->anyStatus()
                ->id($deleteFiles)
                ->all();

            foreach ($assets as $asset) {
                $asset->keepFileOnDelete = true;
                Craft::$app->getElements()->deleteElement($asset);
            }
        }

        return $this->asJson(['stop' => $sessionId]);
    }

    /**
     * Prepare session data for transport.
     *
     * @param AssetIndexingSession $indexingSession
     * @return array
     */
    private function prepareSessionData(AssetIndexingSession $indexingSession): array
    {
        $sessionData = $indexingSession->toArray();
        $sessionData['dateCreated'] = $indexingSession->dateCreated->format('Y-m-d H:i');
        $sessionData['dateUpdated'] = $indexingSession->dateUpdated->format('Y-m-d H:i');
        $sessionData['indexedVolumes'] = Json::decodeIfJson($indexingSession->indexedVolumes);
        return $sessionData;
    }
}