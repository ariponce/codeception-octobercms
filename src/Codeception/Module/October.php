<?php
namespace Codeception\Module;

use Codeception\Exception\ModuleConfig;
use Codeception\Lib\Connector\Laravel5 as LaravelConnector;
use Codeception\Lib\Framework;
use Codeception\Lib\Interfaces\ActiveRecord;
use Codeception\Subscriber\ErrorHandler;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Exception;

/**
 * This module allows you to run functional tests for OctoberCMS.
 * Please try it and leave your feedback.
 * The module is based on the Laravel5 module.
 */
class October extends Framework implements ActiveRecord
{

    /**
     * @var \Illuminate\Foundation\Application
     */
    public $app;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * Constructor.
     *
     * @param $config
     */
    public function __construct($config = null)
    {
        $this->config = array_merge(
            array(
                'cleanup' => true,
                'environment_file' => '.env',
                'bootstrap' => 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
                'root' => '',
                'packages' => 'workbench',
            ),
            (array) $config
        );

        parent::__construct();
    }

    /**
     * Initialize hook.
     */
    public function _initialize()
    {
        $this->revertErrorHandler();
        $this->initializeLaravel();
    }

    /**
     * Before hook.
     *
     * @param \Codeception\TestCase $test
     * @throws ModuleConfig
     */
    public function _before(\Codeception\TestCase $test)
    {
        $this->initializeLaravel();

        if ($this->app['db'] && $this->config['cleanup']) {
            $this->app['db']->beginTransaction();
        }
    }

    /**
     * After hook.
     *
     * @param \Codeception\TestCase $test
     */
    public function _after(\Codeception\TestCase $test)
    {
        if ($this->app['db'] && $this->config['cleanup']) {
            $this->app['db']->rollback();
        }

        if ($this->app['backend.auth']) {
            $this->app['backend.auth']->logout();
        }

        if ($this->app['cache']) {
            $this->app['cache']->flush();
        }

        if ($this->app['session']) {
            $this->app['session']->flush();
        }

        // disconnect from DB to prevent "Too many connections" issue
        if ($this->app['db']) {
            $this->app['db']->disconnect();
        }
    }

    /**
     * After step hook.
     *
     * @param \Codeception\Step $step
     */
    public function _afterStep(\Codeception\Step $step)
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();

        parent::_afterStep($step);
    }

    /**
     * Revert back to the Codeception error handler,
     * becauses Laravel registers it's own error handler.
     */
    protected function revertErrorHandler()
    {
        $handler = new ErrorHandler();
        set_error_handler(array($handler, 'errorHandler'));
    }

    /**
     * Initialize the Laravel framework.
     *
     * @throws ModuleConfig
     */
    protected function initializeLaravel()
    {
        $this->app = $this->bootApplication();
        $this->app->instance('request', new Request());
        $this->client = new LaravelConnector($this->app);
        $this->client->followRedirects(true);
    }

    /**
     * Boot the Laravel application object.
     *
     * @return \Illuminate\Foundation\Application
     * @throws \Codeception\Exception\ModuleConfig
     */
    protected function bootApplication()
    {
        $projectDir = explode($this->config['packages'], \Codeception\Configuration::projectDir())[0];
        $projectDir .= $this->config['root'];
        require $projectDir . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        

        $bootstrapFile = $projectDir . $this->config['bootstrap'];

        if (! file_exists($bootstrapFile)) {
            throw new ModuleConfig(
                $this, "Laravel bootstrap file not found in $bootstrapFile.\nPlease provide a valid path to it using 'bootstrap' config param. "
            );
        }

        $app = require $bootstrapFile;
        

        $kernel = $app->make('Illuminate\Contracts\Console\Kernel');
        $kernel->bootstrap();

        return $app;
    }

	/**
     * Provides access the Laravel application object.
     *
     * @return \Illuminate\Foundation\Application
     */
	public function getApplication()
	{
		return $this->app;
	}

    /**
     * Opens web page using route name and parameters.
     *
     * ```php
     * <?php
     * $I->amOnRoute('posts.create');
     * ?>
     * ```
     *
     * @param $route
     * @param array $params
     */
    public function amOnRoute($route, $params = [])
    {
        $domain = $this->app['routes']->getByName($route)->domain();
        $absolute = ! is_null($domain);

        $url = $this->app['url']->route($route, $params, $absolute);
        $this->amOnPage($url);
    }

    /**
     * Opens web page by action name
     *
     * ```php
     * <?php
     * $I->amOnAction('PostsController@index');
     * ?>
     * ```
     *
     * @param $action
     * @param array $params
     */
    public function amOnAction($action, $params = [])
    {
        $namespacedAction = $this->actionWithNamespace($action);

        $domain = $this->app['routes']->getByAction($namespacedAction)->domain();
        $absolute = ! is_null($domain);

        $url = $this->app['url']->action($action, $params, $absolute);
        $this->amOnPage($url);
    }

    /**
     * Normalize an action to full namespaced action.
     *
     * @param string $action
     * @return string
     */
    protected function actionWithNamespace($action)
    {
        $rootNamespace = $this->getRootControllerNamespace();

        if ($rootNamespace && ! (strpos($action, '\\') === 0)) {
            return $rootNamespace . '\\' . $action;
        } else {
            return trim($action, '\\');
        }
    }

    /**
     * Get the root controller namespace for the application.
     *
     * @return string
     */
    protected function getRootControllerNamespace()
    {
        $urlGenerator = $this->app['url'];
        $reflection = new \ReflectionClass($urlGenerator);

        $property = $reflection->getProperty('rootNamespace');
        $property->setAccessible(true);

        return $property->getValue($urlGenerator);
    }

    /**
     * Checks that current url matches route
     *
     * ```php
     * <?php
     * $I->seeCurrentRouteIs('posts.index');
     * ?>
     * ```
     * @param $route
     * @param array $params
     */
    public function seeCurrentRouteIs($route, $params = array())
    {
        $this->seeCurrentUrlEquals($this->app['url']->route($route, $params, false));
    }

    /**
     * Checks that current url matches action
     *
     * ```php
     * <?php
     * $I->seeCurrentActionIs('PostsController@index');
     * ?>
     * ```
     *
     * @param $action
     * @param array $params
     */
    public function seeCurrentActionIs($action, $params = array())
    {
        $this->seeCurrentUrlEquals($this->app['url']->action($action, $params, false));
    }

    /**
     * Assert that the session has a given list of values.
     *
     * @param  string|array $key
     * @param  mixed $value
     * @return void
     */
    public function seeInSession($key, $value = null)
    {
        if (is_array($key)) {
            $this->seeSessionHasValues($key);
            return;
        }

        if (is_null($value)) {
            $this->assertTrue($this->app['session']->has($key));
        } else {
            $this->assertEquals($value, $this->app['session']->get($key));
        }
    }

    /**
     * Assert that the session has a given list of values.
     *
     * @param  array $bindings
     * @return void
     */
    public function seeSessionHasValues(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $this->seeInSession($value);
            } else {
                $this->seeInSession($key, $value);
            }
        }
    }

    /**
     * Assert that the form errors are bound to the View.
     *
     * @return bool
     */
    public function seeFormHasErrors()
    {
        $viewErrorBag = $this->app->make('view')->shared('errors');
        $this->assertTrue(count($viewErrorBag) > 0);
    }

    /**
     * Assert that specific form error messages are set in the view.
     *
     * Useful for validation messages and generally messages array
     *  e.g.
     *  return `Redirect::to('register')->withErrors($validator);`
     *
     * Example of Usage
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessages(array('username'=>'Invalid Username'));
     * ?>
     * ```
     * @param array $bindings
     */
    public function seeFormErrorMessages(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            $this->seeFormErrorMessage($key, $value);
        }
    }

    /**
     * Assert that specific form error message is set in the view.
     *
     * Useful for validation messages and generally messages array
     *  e.g.
     *  return `Redirect::to('register')->withErrors($validator);`
     *
     * Example of Usage
     *
     * ``` php
     * <?php
     * $I->seeFormErrorMessage('username', 'Invalid Username');
     * ?>
     * ```
     * @param string $key
     * @param string $errorMessage
     */
    public function seeFormErrorMessage($key, $errorMessage)
    {
        $viewErrorBag = $this->app['view']->shared('errors');

        $this->assertEquals($errorMessage, $viewErrorBag->first($key));
    }

    /**
     * Set the currently logged in user for the application.
     * Takes either an object that implements the User interface or
     * an array of credentials.
     *
     * @param  \Illuminate\Contracts\Auth\User|array $user
     * @param  string $driver
     * @return void
     */
    public function amLoggedAs($user, $driver = null)
    {
        if ($user instanceof Authenticatable) {
            $this->app['auth']->driver($driver)->setUser($user);
        } else {
            $this->app['auth']->driver($driver)->attempt($user);
        }
    }

    /**
     * Logs user out
     */
    public function logout()
    {
        $this->app['auth']->logout();
    }

    /**
     * Checks that user is authenticated
     */
    public function seeAuthentication()
    {
        $this->assertTrue($this->app['auth']->check(), 'User is not logged in');
    }

    /**
     * Check that user is not authenticated
     */
    public function dontSeeAuthentication()
    {
        $this->assertFalse($this->app['auth']->check(), 'User is logged in');
    }

    /**
     * Return an instance of a class from the IoC Container.
     * (http://laravel.com/docs/ioc)
     *
     * Example
     * ``` php
     * <?php
     * // In Laravel
     * App::bind('foo', function($app)
     * {
     *     return new FooBar;
     * });
     *
     * // Then in test
     * $service = $I->grabService('foo');
     *
     * // Will return an instance of FooBar, also works for singletons.
     * ?>
     * ```
     *
     * @param  string $class
     * @return mixed
     */
    public function grabService($class)
    {
        return $this->app[$class];
    }

    /**
     * Inserts record into the database.
     *
     * ``` php
     * <?php
     * $user_id = $I->haveRecord('users', array('name' => 'Davert'));
     * ?>
     * ```
     *
     * @param $model
     * @param array $attributes
     * @return mixed
     */
    public function haveRecord($model, $attributes = array())
    {
        $id = $this->app['db']->table($model)->insertGetId($attributes);
        if (!$id) {
            $this->fail("Couldn't insert record into table $model");
        }
        return $id;
    }

    /**
     * Checks that record exists in database.
     *
     * ``` php
     * $I->seeRecord('users', array('name' => 'davert'));
     * ```
     *
     * @param $model
     * @param array $attributes
     */
    public function seeRecord($model, $attributes = array())
    {
        $record = $this->findRecord($model, $attributes);
        if (!$record) {
            $this->fail("Couldn't find $model with " . json_encode($attributes));
        }
        $this->debugSection($model, json_encode($record));
    }

    /**
     * Checks that record does not exist in database.
     *
     * ``` php
     * <?php
     * $I->dontSeeRecord('users', array('name' => 'davert'));
     * ?>
     * ```
     *
     * @param $model
     * @param array $attributes
     */
    public function dontSeeRecord($model, $attributes = array())
    {
        $record = $this->findRecord($model, $attributes);
        $this->debugSection($model, json_encode($record));
        if ($record) {
            $this->fail("Unexpectedly managed to find $model with " . json_encode($attributes));
        }
    }

    /**
     * Retrieves record from database
     *
     * ``` php
     * <?php
     * $category = $I->grabRecord('users', array('name' => 'davert'));
     * ?>
     * ```
     *
     * @param $model
     * @param array $attributes
     * @return mixed
     */
    public function grabRecord($model, $attributes = array())
    {
        return $this->findRecord($model, $attributes);
    }

    /**
     * @param $model
     * @param array $attributes
     * @return mixed
     */
    protected function findRecord($model, $attributes = array())
    {
        $query = $this->app['db']->table($model);
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }
        return $query->first();
    }

}
