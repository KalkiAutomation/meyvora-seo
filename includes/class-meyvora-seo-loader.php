<?php
/**
 * Central action and filter loader for Meyvora SEO.
 * Components register hooks here; run() adds them all to WordPress.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Loader {

	/**
	 * Actions to register.
	 *
	 * @var array<int, array{hook: string, component: object|callable, callback: string|callable, priority: int, accepted_args: int}>
	 */
	protected array $actions = array();

	/**
	 * Filters to register.
	 *
	 * @var array<int, array{hook: string, component: object|callable, callback: string|callable, priority: int, accepted_args: int}>
	 */
	protected array $filters = array();

	/**
	 * Add an action.
	 *
	 * @param string   $hook          WordPress hook name.
	 * @param object   $component     Instance holding the callback.
	 * @param string   $callback      Method name or callable.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments.
	 * @return Meyvora_SEO_Loader
	 */
	public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): Meyvora_SEO_Loader {
		$this->actions[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $this;
	}

	/**
	 * Add a filter.
	 *
	 * @param string   $hook          WordPress hook name.
	 * @param object   $component     Instance holding the callback.
	 * @param string   $callback      Method name or callable.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments.
	 * @return Meyvora_SEO_Loader
	 */
	public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): Meyvora_SEO_Loader {
		$this->filters[] = array(
			'hook'          => $hook,
			'component'     => $component,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $this;
	}

	/**
	 * Register all stored actions and filters with WordPress.
	 */
	public function run(): void {
		foreach ( $this->actions as $action ) {
			add_action(
				$action['hook'],
				array( $action['component'], $action['callback'] ),
				$action['priority'],
				$action['accepted_args']
			);
		}
		foreach ( $this->filters as $filter ) {
			add_filter(
				$filter['hook'],
				array( $filter['component'], $filter['callback'] ),
				$filter['priority'],
				$filter['accepted_args']
			);
		}
	}
}
