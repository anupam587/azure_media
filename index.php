<?php
/**
 * LICENSE: Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once 'vendor/autoload.php';
require_once "WindowsAzure/WindowsAzure.php";

use WindowsAzure\Common\ServicesBuilder;
use WindowsAzure\Common\Internal\MediaServicesSettings;
use WindowsAzure\Common\Internal\Utilities;
use WindowsAzure\MediaServices\Models\Asset;
use WindowsAzure\MediaServices\Models\AccessPolicy;
use WindowsAzure\MediaServices\Models\Locator;
use WindowsAzure\MediaServices\Models\Task;
use WindowsAzure\MediaServices\Models\Job;
use WindowsAzure\MediaServices\Models\TaskOptions;
use WindowsAzure\MediaServices\Models\ContentKey;
use WindowsAzure\MediaServices\Models\ProtectionKeyTypes;
use WindowsAzure\MediaServices\Models\ContentKeyTypes;
use WindowsAzure\MediaServices\Models\ContentKeyAuthorizationPolicy;
use WindowsAzure\MediaServices\Models\ContentKeyAuthorizationPolicyOption;
use WindowsAzure\MediaServices\Models\ContentKeyAuthorizationPolicyRestriction;
use WindowsAzure\MediaServices\Models\ContentKeyDeliveryType;
use WindowsAzure\MediaServices\Models\ContentKeyRestrictionType;
use WindowsAzure\MediaServices\Models\AssetDeliveryPolicy;
use WindowsAzure\MediaServices\Models\AssetDeliveryProtocol;
use WindowsAzure\MediaServices\Models\AssetDeliveryPolicyType;
use WindowsAzure\MediaServices\Models\AssetDeliveryPolicyConfigurationKey;
use WindowsAzure\MediaServices\Templates\PlayReadyLicenseResponseTemplate;
use WindowsAzure\MediaServices\Templates\PlayReadyLicenseTemplate;
use WindowsAzure\MediaServices\Templates\PlayReadyLicenseType;
use WindowsAzure\MediaServices\Templates\MediaServicesLicenseTemplateSerializer;
use WindowsAzure\MediaServices\Templates\WidevineMessage;
use WindowsAzure\MediaServices\Templates\AllowedTrackTypes;
use WindowsAzure\MediaServices\Templates\ContentKeySpecs;
use WindowsAzure\MediaServices\Templates\RequiredOutputProtection;
use WindowsAzure\MediaServices\Templates\Hdcp;
use WindowsAzure\MediaServices\Templates\TokenRestrictionTemplateSerializer;
use WindowsAzure\MediaServices\Templates\TokenRestrictionTemplate;
use WindowsAzure\MediaServices\Templates\SymmetricVerificationKey;
use WindowsAzure\MediaServices\Templates\TokenClaim;
use WindowsAzure\MediaServices\Templates\TokenType;
use WindowsAzure\MediaServices\Templates\WidevineMessageSerializer;

// Settings
//$account = "<your media services account name>";
$account = "tojo";
//$secret = "<your media services account key>";
$secret = "VsiooMU61CACeqUu67w1mn21lBOAyjvwKVSO2AL16Io=";
$mezzanineFileName = "Azure-Video.wmv";
$tokenRestriction = true;
//$tokenType = TokenType::JWT;
$tokenType = "JWT";

print "Azure SDK for PHP - PlayReady + Widevine Dynamic Encryption Sample\r\n";

// 0 - set up the MediaServicesService object to call into the Media Services REST API.
$restProxy = ServicesBuilder::getInstance()->createMediaServicesService(new MediaServicesSettings($account, $secret));

// 1 - Upload the mezzanine
$sourceAsset = uploadFileAndCreateAsset($restProxy, $mezzanineFileName);

// 2 - encode the output asset
$encodedAsset = encodeToAdaptiveBitrateMP4Set($restProxy, $sourceAsset);

// 3 - Create Content Key
$contentKey = createCommonTypeContentKey($restProxy, $encodedAsset);

// 4 - Create the ContentKey Authorization Policy
$tokenTemplateString = null;
if ($tokenRestriction) {
    $tokenTemplateString = addTokenRestrictedAuthorizationPolicy($restProxy, $contentKey, $tokenType);
} else {
    addOpenAuthorizationPolicy($restProxy, $contentKey);
}
// 5 - Create the AssetDeliveryPolicy
createAssetDeliveryPolicy($restProxy, $encodedAsset, $contentKey);

// 6 - Publish
publishEncodedAsset($restProxy, $encodedAsset);

// 7 - Generate Test Token
if ($tokenRestriction) {
    generateTestToken($tokenTemplateString, $contentKey);
}

// Done
print "Done!";

////////////////////
// Helper methods //
////////////////////

function uploadFileAndCreateAsset($restProxy, $mezzanineFileName) {
    // 1.1. create an empty "Asset" by specifying the name
    $asset = new Asset(Asset::OPTIONS_NONE);
    $asset->setName("Mezzanine " . $mezzanineFileName);
    $asset = $restProxy->createAsset($asset);
    $assetId = $asset->getId();

    print "Asset created: name=" . $asset->getName() . " id=" . $assetId . "\r\n";

    // 1.3. create an Access Policy with Write permissions
    $accessPolicy = new AccessPolicy('UploadAccessPolicy');
    $accessPolicy->setDurationInMinutes(60.0);
    $accessPolicy->setPermissions(AccessPolicy::PERMISSIONS_WRITE);
    $accessPolicy = $restProxy->createAccessPolicy($accessPolicy);

    // 1.4. create a SAS Locator for the Asset
    $sasLocator = new Locator($asset,  $accessPolicy, Locator::TYPE_SAS);
    $sasLocator->setStartTime(new \DateTime('now -5 minutes'));
    $sasLocator = $restProxy->createLocator($sasLocator);

    // 1.5. get the mezzanine file content
    $fileContent = file_get_contents($mezzanineFileName);

    print "Uploading...\r\n";

    // 1.6. use the 'uploadAssetFile' to perform a multi-part upload using the Block Blobs REST API storage operations
    $restProxy->uploadAssetFile($sasLocator, $mezzanineFileName, $fileContent);

    // 1.7. notify Media Services that the file upload operation is done to generate the asset file metadata
    $restProxy->createFileInfos($asset);

    print "File uploaded: size=" . strlen($fileContent) . "\r\n";

    // 1.8. delete the SAS Locator (and Access Policy) for the Asset since we are done uploading files
    $restProxy->deleteLocator($sasLocator);
    $restProxy->deleteAccessPolicy($accessPolicy);
    return $asset;
}

function encodeToAdaptiveBitrateMP4Set($restProxy, $asset) {
    // 2.1 retrieve the latest 'Media Encoder Standard' processor version
    $mediaProcessor = $restProxy->getLatestMediaProcessor('Media Encoder Standard');

    print "Using Media Processor: {$mediaProcessor->getName()} version {$mediaProcessor->getVersion()}\r\n";

    // 2.2 Create the Job; this automatically schedules and runs it
    $outputAssetName = "Encoded " . $asset->getName();
    $outputAssetCreationOption = Asset::OPTIONS_NONE;
    $taskBody = '<?xml version="1.0" encoding="utf-8"?><taskBody><inputAsset>JobInputAsset(0)</inputAsset><outputAsset assetCreationOptions="' . $outputAssetCreationOption . '" assetName="' . $outputAssetName . '">JobOutputAsset(0)</outputAsset></taskBody>';

    $task = new Task($taskBody, $mediaProcessor->getId(), TaskOptions::NONE);
    $task->setConfiguration('H264 Multiple Bitrate 720p');

    $job = new Job();
    $job->setName('Encoding Job');

    $job = $restProxy->createJob($job, array($asset), array($task));

    print "Created Job with Id: {$job->getId()}\r\n";

    // 2.3 Check to see if the Job has completed
    $result = $restProxy->getJobStatus($job);

    $jobStatusMap = array('Queued', 'Scheduled', 'Processing', 'Finished', 'Error', 'Canceled', 'Canceling');

    while($result != Job::STATE_FINISHED && $result != Job::STATE_ERROR && $result != Job::STATE_CANCELED) {
        print "Job status: {$jobStatusMap[$result]}\r\n";
        sleep(5);
        $result = $restProxy->getJobStatus($job);
    }

    if ($result != Job::STATE_FINISHED) {
        print "The job has finished with a wrong status: {$jobStatusMap[$result]}\r\n";
        exit(-1);
    }

    print "Job Finished!\r\n";

    // 2.4 Get output asset
    $outputAssets = $restProxy->getJobOutputMediaAssets($job);
    $encodedAsset = $outputAssets[0];

    print "Asset encoded: name={$encodedAsset->getName()} id={$encodedAsset->getId()}\r\n";

    return $encodedAsset;
}

function createCommonTypeContentKey($restProxy, $encodedAsset) {
    // 3.1 Generate a new key
    $aesKey = Utilities::generateCryptoKey(16);

    // 3.2 Get the protection key id for ContentKey
    $protectionKeyId = $restProxy->getProtectionKeyId(ContentKeyTypes::COMMON_ENCRYPTION);
    $protectionKey = $restProxy->getProtectionKey($protectionKeyId);

    $contentKey = new ContentKey();
    $contentKey->setContentKey($aesKey, $protectionKey);
    $contentKey->setProtectionKeyId($protectionKeyId);
    $contentKey->setProtectionKeyType(ProtectionKeyTypes::X509_CERTIFICATE_THUMBPRINT);
    $contentKey->setContentKeyType(ContentKeyTypes::COMMON_ENCRYPTION);

    // 3.3 Create the ContentKey
    $contentKey = $restProxy->createContentKey($contentKey);

    print "Content Key id={$contentKey->getId()}\r\n";

    // 3.4 Associate the ContentKey with the Asset
    $restProxy->linkContentKeyToAsset($encodedAsset, $contentKey);

    return $contentKey;
}

function addOpenAuthorizationPolicy($restProxy, $contentKey) {
    // 4.1 Create ContentKeyAuthorizationPolicyRestriction (Open)
    $restriction = new ContentKeyAuthorizationPolicyRestriction();
    $restriction->setName('ContentKey Authorization Policy Restriction');
    $restriction->setKeyRestrictionType(ContentKeyRestrictionType::OPEN);

    // 4.2 Configure PlayReady and Widevine license templates.
    $playReadyLicenseTemplate = configurePlayReadyLicenseTemplate();
    $widevineLicenseTemplate = configureWidevineLicenseTemplate();

    // 4.3 Create ContentKeyAuthorizationPolicyOption (PlayReady)
    $playReadyOption = new ContentKeyAuthorizationPolicyOption();
    $playReadyOption->setName('PlayReady Authorization Policy Option');
    $playReadyOption->setKeyDeliveryType(ContentKeyDeliveryType::PLAYREADY_LICENSE);
    $playReadyOption->setRestrictions(array($restriction));
    $playReadyOption->setKeyDeliveryConfiguration($playReadyLicenseTemplate);
    $playReadyOption = $restProxy->createContentKeyAuthorizationPolicyOption($playReadyOption);

    // 4.4 Create ContentKeyAuthorizationPolicyOption (Widevine)
    $widevineOption = new ContentKeyAuthorizationPolicyOption();
    $widevineOption->setName('Widevine Authorization Policy Option');
    $widevineOption->setKeyDeliveryType(ContentKeyDeliveryType::WIDEVINE);
    $widevineOption->setRestrictions(array($restriction));
    $widevineOption->setKeyDeliveryConfiguration($widevineLicenseTemplate);
    $widevineOption = $restProxy->createContentKeyAuthorizationPolicyOption($widevineOption);

    // 4.5 Create ContentKeyAuthorizationPolicy
    $ckapolicy = new ContentKeyAuthorizationPolicy();
    $ckapolicy->setName('ContentKey Authorization Policy');
    $ckapolicy = $restProxy->createContentKeyAuthorizationPolicy($ckapolicy);

    // 4.6 Link the ContentKeyAuthorizationPolicyOption to the ContentKeyAuthorizationPolicy
    $restProxy->linkOptionToContentKeyAuthorizationPolicy($playReadyOption, $ckapolicy);
    $restProxy->linkOptionToContentKeyAuthorizationPolicy($widevineOption, $ckapolicy);

    // 4.7 Associate the ContentKeyAuthorizationPolicy with the ContentKey
    $contentKey->setAuthorizationPolicyId($ckapolicy->getId());
    $restProxy->updateContentKey($contentKey);

    print "Added Content Key Authorization Policy: name={$ckapolicy->getName()} id={$ckapolicy->getId()}\r\n";
}

function addTokenRestrictedAuthorizationPolicy($restProxy, $contentKey, $tokenType) {
    // 4.1 Create ContentKeyAuthorizationPolicyRestriction (Token Restricted)
    $tokenRestriction = generateTokenRequirements($tokenType);
    $restriction = new ContentKeyAuthorizationPolicyRestriction();
    $restriction->setName('ContentKey Authorization Policy Restriction');
    $restriction->setKeyRestrictionType(ContentKeyRestrictionType::TOKEN_RESTRICTED);
    $restriction->setRequirements($tokenRestriction);

    // 4.2 Configure PlayReady and Widevine license templates.
    $playReadyLicenseTemplate = configurePlayReadyLicenseTemplate();
    $widevineLicenseTemplate = configureWidevineLicenseTemplate();

    // 4.3 Create ContentKeyAuthorizationPolicyOption (PlayReady)
    $playReadyOption = new ContentKeyAuthorizationPolicyOption();
    $playReadyOption->setName('PlayReady Authorization Policy Option');
    $playReadyOption->setKeyDeliveryType(ContentKeyDeliveryType::PLAYREADY_LICENSE);
    $playReadyOption->setRestrictions(array($restriction));
    $playReadyOption->setKeyDeliveryConfiguration($playReadyLicenseTemplate);
    $playReadyOption = $restProxy->createContentKeyAuthorizationPolicyOption($playReadyOption);

    // 4.4 Create ContentKeyAuthorizationPolicyOption (Widevine)
    $widevineOption = new ContentKeyAuthorizationPolicyOption();
    $widevineOption->setName('Widevine Authorization Policy Option');
    $widevineOption->setKeyDeliveryType(ContentKeyDeliveryType::WIDEVINE);
    $widevineOption->setRestrictions(array($restriction));
    $widevineOption->setKeyDeliveryConfiguration($widevineLicenseTemplate);
    $widevineOption = $restProxy->createContentKeyAuthorizationPolicyOption($widevineOption);

    // 4.5 Create ContentKeyAuthorizationPolicy
    $ckapolicy = new ContentKeyAuthorizationPolicy();
    $ckapolicy->setName('ContentKey Authorization Policy');
    $ckapolicy = $restProxy->createContentKeyAuthorizationPolicy($ckapolicy);

    // 4.6 Link the ContentKeyAuthorizationPolicyOption to the ContentKeyAuthorizationPolicy
    $restProxy->linkOptionToContentKeyAuthorizationPolicy($playReadyOption, $ckapolicy);
    $restProxy->linkOptionToContentKeyAuthorizationPolicy($widevineOption, $ckapolicy);

    // 4.7 Associate the ContentKeyAuthorizationPolicy with the ContentKey
    $contentKey->setAuthorizationPolicyId($ckapolicy->getId());
    $restProxy->updateContentKey($contentKey);

    print "Added Content Key Authorization Policy: name={$ckapolicy->getName()} id={$ckapolicy->getId()}\r\n";
    return $tokenRestriction;
}

function createAssetDeliveryPolicy($restProxy, $encodedAsset, $contentKey) {
    // 5.1 Get the acquisition URL
    $acquisitionUrl = $restProxy->getKeyDeliveryUrl($contentKey, ContentKeyDeliveryType::PLAYREADY_LICENSE);
    $widevineURl = $restProxy->getKeyDeliveryUrl($contentKey, ContentKeyDeliveryType::WIDEVINE);

    // 5.2 Generate the AssetDeliveryPolicy Configuration Key
    $configuration = [AssetDeliveryPolicyConfigurationKey::PLAYREADY_LICENSE_ACQUISITION_URL => $acquisitionUrl,
                      AssetDeliveryPolicyConfigurationKey::WIDEVINE_LICENSE_ACQUISITION_URL => $widevineURl];
    $confJson = AssetDeliveryPolicyConfigurationKey::stringifyAssetDeliveryPolicyConfiguartionKey($configuration);

    // 5.3 Create the AssetDeliveryPolicy
    $adpolicy = new AssetDeliveryPolicy();
    $adpolicy->setName('Asset Delivery Policy');
    $adpolicy->setAssetDeliveryProtocol(AssetDeliveryProtocol::DASH);
    $adpolicy->setAssetDeliveryPolicyType(AssetDeliveryPolicyType::DYNAMIC_COMMON_ENCRYPTION);
    $adpolicy->setAssetDeliveryConfiguration($confJson);

    $adpolicy = $restProxy->createAssetDeliveryPolicy($adpolicy);

    // 5.4 Link the AssetDeliveryPolicy to the Asset
    $restProxy->linkDeliveryPolicyToAsset($encodedAsset, $adpolicy->getId());

    print "Added Asset Delivery Policy: name={$adpolicy->getName()} id={$adpolicy->getId()}\r\n";
}

function publishEncodedAsset($restProxy, $encodedAsset) {
    // 6.1 Get the .ISM AssetFile
    $files = $restProxy->getAssetAssetFileList($encodedAsset);
    $manifestFile = null;

    foreach($files as $file) {
        if (endsWith(strtolower($file->getName()), '.ism')) {
            $manifestFile = $file;
        }
    }

    if ($manifestFile == null) {
        print "Unable to found the manifest file\r\n";
        exit(-1);
    }

    // 6.2 Create a 30-day read-only AccessPolicy
    $access = new AccessPolicy("Streaming Access Policy");
    $access->setDurationInMinutes(60 * 24 * 30);
    $access->setPermissions(AccessPolicy::PERMISSIONS_READ);
    $access = $restProxy->createAccessPolicy($access);

    // 6.3 Create a Locator using the AccessPolicy and Asset
    $locator = new Locator($encodedAsset, $access, Locator::TYPE_ON_DEMAND_ORIGIN);
    $locator->setName("Streaming Locator");
    $locator = $restProxy->createLocator($locator);

    // 6.4 Create a Smooth Streaming base URL
    $stremingUrl = $locator->getPath() . $manifestFile->getName() . "/manifest";

    print "Streaming URL: {$stremingUrl}\r\n";
}

function configurePlayReadyLicenseTemplate() {
    // The following code configures PlayReady License Template using PHP classes
    // and returns the XML string.

    //The PlayReadyLicenseResponseTemplate class represents the template for the response sent back to the end user.
    //It contains a field for a custom data string between the license server and the application
    //(may be useful for custom app logic) as well as a list of one or more license templates.
    $responseTemplate = new PlayReadyLicenseResponseTemplate();

    // The PlayReadyLicenseTemplate class represents a license template for creating PlayReady licenses
    // to be returned to the end users.
    //It contains the data on the content key in the license and any rights or restrictions to be
    //enforced by the PlayReady DRM runtime when using the content key.
    $licenseTemplate = new PlayReadyLicenseTemplate();

    //Configure whether the license is persistent (saved in persistent storage on the client)
    //or non-persistent (only held in memory while the player is using the license).
    $licenseTemplate->setLicenseType(PlayReadyLicenseType::NON_PERSISTENT);

    // AllowTestDevices controls whether test devices can use the license or not.
    // If true, the MinimumSecurityLevel property of the license
    // is set to 150.  If false (the default), the MinimumSecurityLevel property of the license is set to 2000.
    $licenseTemplate->setAllowTestDevices(true);

    // You can also configure the Play Right in the PlayReady license by using the PlayReadyPlayRight class.
    // It grants the user the ability to playback the content subject to the zero or more restrictions
    // configured in the license and on the PlayRight itself (for playback specific policy).
    // Much of the policy on the PlayRight has to do with output restrictions
    // which control the types of outputs that the content can be played over and
    // any restrictions that must be put in place when using a given output.
    // For example, if the DigitalVideoOnlyContentRestriction is enabled,
    //then the DRM runtime will only allow the video to be displayed over digital outputs
    //(analog video outputs won�t be allowed to pass the content).

    //IMPORTANT: These types of restrictions can be very powerful but can also affect the consumer experience.
    // If the output protections are configured too restrictive,
    // the content might be unplayable on some clients. For more information, see the PlayReady Compliance Rules document.

    // For example:
    //$licenseTemplate->getPlayRight()->setAgcAndColorStripeRestriction(new AgcAndColorStripeRestriction(1));

    $responseTemplate->setLicenseTemplates(array($licenseTemplate));

    return MediaServicesLicenseTemplateSerializer::serialize($responseTemplate);
}

function configureWidevineLicenseTemplate() {
    $template = new WidevineMessage();
    $template->allowed_track_types = AllowedTrackTypes::SD_HD;
    $contentKeySpecs = new ContentKeySpecs();
    $contentKeySpecs->required_output_protection = new RequiredOutputProtection();
    $contentKeySpecs->required_output_protection->hdcp = Hdcp::HDCP_NONE;
    $contentKeySpecs->security_level = 1;
    $contentKeySpecs->track_type = "SD";
    $template->content_key_specs = array($contentKeySpecs);
    $policyOverrides  = new \stdClass();
    $policyOverrides->can_play = true;
    $policyOverrides->can_persist = true;
    $policyOverrides->can_renew = false;
    $template->policy_overrides = $policyOverrides;

    return WidevineMessageSerializer::serialize($template);
}

function generateTokenRequirements($tokenType) {
    $template = new TokenRestrictionTemplate($tokenType);

    $template->setPrimaryVerificationKey(new SymmetricVerificationKey());
    $template->setAudience("urn:contoso");
    $template->setIssuer("https://sts.contoso.com");
    $claims = array();
    $claims[] = new TokenClaim(TokenClaim::CONTENT_KEY_ID_CLAIM_TYPE);
    $template->setRequiredClaims($claims);

    return TokenRestrictionTemplateSerializer::serialize($template);
}

function generateTestToken($tokenTemplateString, $contentKey) {
    $template = TokenRestrictionTemplateSerializer::deserialize($tokenTemplateString);
    $contentKeyUUID = substr($contentKey->getId(), strlen("nb:kid:UUID:"));
    $expiration = strtotime("+12 hour");
    $token = TokenRestrictionTemplateSerializer::generateTestToken($template, null, $contentKeyUUID, $expiration);

    print "Token Type {$template->getTokenType()}\r\nBearer={$token}\r\n";
}

function endsWith($haystack, $needle) {
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

?>
