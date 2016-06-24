<?php

namespace YBoard\Abstracts;

use YBoard;
use YBoard\Library\Database;
use YBoard\Library\HttpResponse;
use YBoard\Library\i18n;
use YBoard\Library\TemplateEngine;
use YBoard\Model;
use YBoard\Traits\ErrorPages;

abstract class ExtendedController extends YBoard\Controller
{
    use ErrorPages;

    protected $config;
    protected $i18n;
    protected $db;
    protected $boards;
    protected $user;
    protected $locale;

    public function __construct()
    {
        // Load config
        $this->config = require(ROOT_PATH . '/YBoard/Config/YBoard.php');

        // Get a database connection
        $this->db = new Database(require(ROOT_PATH . '/YBoard/Config/Database.php'));

        // Load some data that are required on almost every page, like the list of boards and user data
        $this->boards = new Model\Boards($this->db);

        // Load internalization
        $this->i18n = new i18n(ROOT_PATH . '/YBoard/i18n');

        // Load user
        $this->loadUser();

        // Get locale
        $this->locale = $this->i18n->getPreferredLocale();
        if (!$this->locale) {
            // Fallback
            $this->locale = $this->config['i18n']['defaultLocale'];
        }

        // Set locale
        $this->i18n->loadLocale($this->locale);
    }

    public function __destruct()
    {
        // Debug: Execution time and memory usage
        echo '<!-- ', round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000,
            2), ' ms ', round(memory_get_usage() / 1024 / 1024, 2) . ' MB', ' -->';
    }

    protected function loadUser()
    {
        $this->user = new Model\User($this->db);

        if (!empty($_COOKIE['user'])) {
            $session = str_split($_COOKIE['user'], 32);

            if (count($session) != 2) {
                $this->deleteLoginCookie();
            }

            $userId = $session[1];
            $sessionId = $session[0];

            $load = $this->user->load($userId);
            if (!$load) {
                $this->deleteLoginCookie();
            }

            if (!$this->user->verifySession($userId, $sessionId)) {
                $this->deleteLoginCookie();
            }
        } else {
            // Session does not exist
            if ($this->userMaybeBot()) {
                return false;
            }

            $createUser = $this->user->create();
            if (!$createUser) {
                $this->dieWithError();
            }
            $createSession = $this->user->createSession($this->user->id);
            if (!$createSession) {
                $this->dieWithError();
            }

            $this->setLoginCookie($this->user->sessionId . $this->user->id);
        }
    }

    protected function setLoginCookie($val)
    {
        HttpResponse::setCookie('user', $val);
    }

    protected function deleteLoginCookie($reload = true)
    {
        HttpResponse::setCookie('user', '', false);
        if ($reload) {
            HttpResponse::redirectExit($_SERVER['REQUEST_URI']);
        }
    }

    protected function userMaybeBot()
    {
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) // Great way of detecting crawlers!
        {
            return true;
        }

        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        if (preg_match('/Baiduspider/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        if (preg_match('/msnbot/i', $_SERVER['HTTP_USER_AGENT'])) {
            return true;
        }

        return false;
    }

    protected function dieWithMessage($errorTitle, $errorMessage, $httpStatus = false)
    {
        if ($httpStatus && is_int($httpStatus)) {
            HttpResponse::setStatusCode($httpStatus);
        }
        $view = $this->loadTemplateEngine();

        $view->pageTitle = $view->errorTitle = $errorTitle;
        $view->errorMessage = $errorMessage;

        $view->bodyClass = 'error';
        if ($httpStatus == 500) {
            $images = glob(ROOT_PATH . '/static/img/500/*.*');
            $view->bodyClass .= ' internalerror';
        } elseif ($httpStatus == 404) {
            $images = glob(ROOT_PATH . '/static/img/404/*.*');
            $view->bodyClass .= ' notfound';
        }
        if (!empty($images)) {
            $view->errorImageSrc = $this->config['app']['staticUrl'] . str_replace(ROOT_PATH . '/static', '',
                    $images[array_rand($images)]);
        }

        $view->display('Error');
        $this->stopExecution();
    }

    protected function loadTemplateEngine($templateFile = 'Default')
    {
        $templateEngine = new TemplateEngine(ROOT_PATH . '/YBoard/View/', $templateFile);

        foreach ($this->config['app'] as $var => $val) {
            $templateEngine->$var = $val;
        }

        $templateEngine->boardList = $this->boards->getAll();

        return $templateEngine;
    }

    protected function disallowNonPost()
    {
        if (!$this->isPostRequest()) {
            HttpResponse::setStatusCode(405, ['Allowed' => 'POST']);
            $this->stopExecution();
        }
    }

    protected function isPostRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            return true;
        }

        return false;
    }

    protected function invalidAjaxData()
    {
        HttpResponse::setStatusCode(401);
        $this->jsonMessage('Virheellinen pyyntö', true);
        $this->stopExecution();
    }

    protected function jsonMessage($str = '', $error = false)
    {
        echo json_encode(['error' => $error, 'message' => $str]);
    }

    protected function validateCsrfToken($token)
    {
        if (empty($token) || empty($this->user->csrfToken)) {
            return false;
        }

        if ($token == $this->user->csrfToken) {
            return true;
        }

        return false;
    }

    protected function validateAjaxCsrfToken()
    {
        if (!$this->isPostRequest()) {
            $this->ajaxCsrfValidationFail();
        }

        if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($this->user->csrfToken)) {
            $this->ajaxCsrfValidationFail();
        }

        if ($_SERVER['HTTP_X_CSRF_TOKEN'] == $this->user->csrfToken) {
            return true;
        }

        $this->ajaxCsrfValidationFail();

        return false;
    }

    protected function ajaxCsrfValidationFail()
    {
        HttpResponse::setStatusCode(401);
        $this->jsonMessage('Istuntosi on vanhentunut. Ole hyvä ja päivitä tämä sivu.', true);
        $this->stopExecution();
    }

    protected function jsonError($str = '')
    {
        $this->jsonMessage($str, true);
        $this->stopExecution();
    }
}
