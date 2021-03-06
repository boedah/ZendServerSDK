<?php
namespace Client\Controller;
use Zend\Mvc\Controller\AbstractActionController;
use Client\Service\ZpkInvokable;

/**
 * App Console Controller
 *
 * High-Level Application Deployment CLI commands
 */
class AppController extends AbstractActionController
{
    public function installAction()
    {
        $requestParameters = array();
        $zpk     = $this->params('zpk');
        $baseUri = $this->params('baseUri');
        $userParams = $this->params('userParams', array());
        $appName    = $this->params('userAppName');
        $appId      = 0;
        $wait       = $this->params('wait');

        $apiManager = $this->serviceLocator->get('zend_server_api');
        $zpkService = $this->serviceLocator->get('zpk');
        try {
            $xml = $zpkService->getMeta($zpk);
        } catch (\ErrorException $ex) {
            throw new \Zend\Mvc\Exception\RuntimeException($ex->getMessage(), $ex->getCode(), $ex);
        }

        if (isset($xml->type) && $xml->type == ZpkInvokable::TYPE_LIBRARY) {
            return $this->forward()->dispatch('webapi-lib-controller');
        }

        // validate the package
        $zpkService->validateMeta($zpk);

        if (!$appName) {
            // get the name of the application from the package
            $appName = sprintf("%s", $xml->name);
            // or the baseUri
            if (!$appName) {
                $appName = str_replace($baseUri, '/', '');
            }
        }

        // check what applications are deployed
        $response = $apiManager->applicationGetStatus();
        foreach ($response->responseData->applicationsList->applicationInfo as $appElement) {
            if ($appElement->userAppName == $appName) {
                $appId = $appElement->id;
                break;
            }
        }

        if (!$appId) {
            $params = array(
                'action'      => 'applicationDeploy',
                'appPackage'  => $zpk,
                'baseUrl'     => $baseUri,
                'userAppName' => $appName,
                'userParams'  => $userParams,
            );

            $optionalParams = array('createVhost', 'defaultServer', 'ignoreFailures');
            foreach ($optionalParams as $key) {
                $value = $this->params($key);
                if ($value) {
                    $params[$key] = $value;
                }
            }
            $response = $this->forward()->dispatch('webapi-api-controller',$params);
            if($wait) {
                $xml = new \SimpleXMLElement($response->getBody());
                $appId = $xml->responseData->applicationInfo->id;
            }

        } else {
            // otherwise update the application
            $response = $this->forward()->dispatch('webapi-api-controller',array(
                'action'     => 'applicationUpdate',
                'appId'      => $appId,
                'appPackage' => $zpk,
                'userParams' => $userParams,
            ));
        }

        if($wait) {
            $response = $this->repeater()->doUntil(array($this,'onWaitInstall'), array('appId'=>sprintf("%s",$appId)));
        }

        return $response;
    }

    /**
     * Returns response if the action finished as expected
     * @param AbstractActionController $controller
     * @param array $params
     */
    public function onWaitInstall($controller, $params)
    {
        $appId = $params['appId'];
        $response = $controller->forward()->dispatch('webapi-api-controller',array(
                    'action'     => 'applicationGetStatus',
                    'applications'  => array($appId)
        ));
        $xml = new \SimpleXMLElement($response->getBody());

        $status = (string)$xml->responseData->applicationsList->applicationInfo->status;
        if(stripos($status,'error')!==false) {
            throw new \Exception(sprintf("Got error '%s' during deployment.\nThe followin error message is reported from the server:\n%s", $status, $xml->responseData->applicationsList->applicationInfo->messageList->error));
        }

        if($status !='deployed') {
            return;
        }

        return $response;
    }
}
