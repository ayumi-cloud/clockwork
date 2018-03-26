<?php namespace Clockwork\Support\Laravel;

use Clockwork\Clockwork;
use Clockwork\DataSource\PhpDataSource;
use Clockwork\DataSource\LaravelDataSource;
use Clockwork\DataSource\LaravelCacheDataSource;
use Clockwork\DataSource\LaravelEventsDataSource;
use Clockwork\DataSource\EloquentDataSource;
use Clockwork\DataSource\SwiftDataSource;

use Illuminate\Support\ServiceProvider;

class ClockworkServiceProvider extends ServiceProvider
{
	public function boot()
	{
		if ($this->app['clockwork.support']->isCollectingData()) {
			$this->listenToEvents();
		}

		if (! $this->app['clockwork.support']->isEnabled()) {
			return; // Clockwork is disabled, don't register the middleware and routes
		}

		$this->registerRoutes();

		// register the Clockwork Web UI routes
		if ($this->app['clockwork.support']->isWebEnabled()) {
			$this->registerWebRoutes();
		}
	}

	protected function listenToEvents()
	{
		$this->app['clockwork.laravel']->listenToEvents();

		if ($this->app['clockwork.support']->isCollectingDatabaseQueries()) {
			$this->app['clockwork.eloquent']->listenToEvents();
		}

		if ($this->app['clockwork.support']->isCollectingCacheStats()) {
			$this->app['clockwork.cache']->listenToEvents();
		}

		if ($this->app['clockwork.support']->isCollectingEvents()) {
			$this->app['clockwork.events']->listenToEvents();
		}
	}

	public function register()
	{
		$this->publishes([ __DIR__ . '/config/clockwork.php' => config_path('clockwork.php') ]);
		$this->mergeConfigFrom(__DIR__ . '/config/clockwork.php', 'clockwork');

		$this->app->singleton('clockwork.support', function ($app) {
			return new ClockworkSupport($app);
		});

		$this->app->singleton('clockwork.laravel', function ($app) {
			return new LaravelDataSource($app);
		});

		$this->app->singleton('clockwork.swift', function ($app) {
			return new SwiftDataSource($app['mailer']->getSwiftMailer());
		});

		$this->app->singleton('clockwork.eloquent', function ($app) {
			return (new EloquentDataSource($app['db'], $app['events']))
				->collectStackTraces($app['clockwork.support']->getConfig('collect_stack_traces'));
		});

		$this->app->singleton('clockwork.cache', function ($app) {
			return (new LaravelCacheDataSource($app['events']))
				->collectStackTraces($app['clockwork.support']->getConfig('collect_stack_traces'));
		});

		$this->app->singleton('clockwork.events', function ($app) {
			$support = $app['clockwork.support'];
			return (new LaravelEventsDataSource($app['events'], $support->getConfig('ignored_events', [])))
				->collectStackTraces($support->getConfig('collect_stack_traces'));
		});

		$this->app->singleton('clockwork', function ($app) {
			$clockwork = new Clockwork();
			$support = $app['clockwork.support'];

			$clockwork
				->addDataSource(new PhpDataSource())
				->addDataSource($app['clockwork.laravel'])
				->addDataSource($app['clockwork.swift']);

			if ($support->isCollectingDatabaseQueries()) {
				$clockwork->addDataSource($app['clockwork.eloquent']);
			}

			if ($support->isCollectingCacheStats()) {
				$clockwork->addDataSource($app['clockwork.cache']);
			}

			if ($support->isCollectingEvents()) {
				$clockwork->addDataSource($app['clockwork.events']);
			}

			if ($support->getConfig('collect_stack_traces')) {
				$clockwork->getLog()->collectStackTraces(true);
			}

			$clockwork->setStorage($support->getStorage());

			return $clockwork;
		});

		$this->app['clockwork.laravel']->listenToEarlyEvents();

		// set up aliases for all Clockwork parts so they can be resolved by the IoC container
		$this->app->alias('clockwork.support', ClockworkSupport::class);
		$this->app->alias('clockwork.laravel', LaravelDataSource::class);
		$this->app->alias('clockwork.swift', SwiftDataSource::class);
		$this->app->alias('clockwork.eloquent', EloquentDataSource::class);
		$this->app->alias('clockwork', Clockwork::class);

		$this->registerCommands();
		$this->registerMiddleware();

		if ($this->app['clockwork.support']->getConfig('register_helpers', true)) {
			require __DIR__ . '/helpers.php';
		}
	}

	// Register the artisan commands.
	public function registerCommands()
	{
		$this->commands([
			ClockworkCleanCommand::class
		]);
	}

	// Register middleware
	public function registerMiddleware()
	{
		$this->app[\Illuminate\Contracts\Http\Kernel::class]
			->prependMiddleware(ClockworkMiddleware::class);
	}

	public function registerRoutes()
	{
		$this->app['router']->get('/__clockwork/{id}/{direction?}/{count?}', 'Clockwork\Support\Laravel\ClockworkController@getData')
			->where('id', '([0-9-]+|latest)')->where('direction', '(next|previous)')->where('count', '\d+');
	}

	public function registerWebRoutes()
	{
		$this->app['router']->get('/__clockwork', 'Clockwork\Support\Laravel\ClockworkController@webRedirect');
		$this->app['router']->get('/__clockwork/app', 'Clockwork\Support\Laravel\ClockworkController@webIndex');
		$this->app['router']->get('/__clockwork/assets/{path}', 'Clockwork\Support\Laravel\ClockworkController@webAsset')->where('path', '.+');
	}

	public function provides()
	{
		return [ 'clockwork' ];
	}
}
