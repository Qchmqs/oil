<?php
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package    Fuel
 * @version    1.8
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2017 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Tasks;

/**
 * Run scaffolding or model generation based an an existing database tables.
 *
 * Based on https://github.com/mp-php/fuel-myapp/blob/master/tasks/scafdb.php
 *
 * @author     Mamoru Otsuka http://madroom-project.blogspot.jp/
 * @copyright  2012 Mamoru Otsuka
 * @license    WTFPL http://sam.zoy.org/wtfpl/COPYING
 */
class Fromdb
{
	/**
	 * Class initialization
	 */
	public function __construct()
	{
		// load the migrations config
		\Config::load('migrations', true);
	}

	/**
	 * Show help.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine fromdb
	 */
	public static function run()
	{
		static::help();
	}

	/**
	 * Show help.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine fromdb:help
	 */
	public static function help()
	{
		$output = <<<HELP

Description:
  Run scaffolding, generate a model or generate migrations from existing
  database table(s). Database settings must be configured correctly for
  this to work.

Runtime options:
  -f, [--force]       # Overwrite files that already exist
  -s, [--skip]        # Skip generating files that already exist
  -a, [--admin]       # Generate admin scaffolding code
  --all               # Generate code for all tables found in the database
  --db=<database>     # Name of the database to use

Commands:
  php oil refine fromdb:scaffold <table_name,table_name...>
  php oil refine fromdb:scaffold --all
  php oil refine fromdb:migration <table_name,table_name...>
  php oil refine fromdb:migration --all
  php oil refine fromdb:model <table_name,table_name...>
  php oil refine fromdb:model --all
  php oil refine fromdb:help

HELP;
		\Cli::write($output);
	}

	/**
	 * Generate scaffold for a database table.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine fromdb:scaffold <table_name,table_name...>
	 */
	public static function scaffold($tables = null)
	{
		// do we have any tables defined?
		if (empty($tables))
		{
			// fetch them all if possible
			$tables = static::fetch_tables('scaffolding');
		}
		else
		{
			// make sure we have an array to work with
			is_array($tables) or $tables = explode(',', $tables);
		}

		// check what kind of models we need to generate
		$subfolder = \Cli::option('crud') ? 'crud' : 'orm';

		// generate for each table defined
		foreach ($tables as $table)
		{
			// start with an empty list
			\Oil\Generate::$create_files = array();

			// and generate
			if (\Cli::option('admin', \Cli::option('a', false)))
			{
				call_user_func('\\Oil\\Generate_Admin::forge', static::arguments($table), $subfolder);
			}
			else
			{
				call_user_func('\\Oil\\Generate_Scaffold::forge', static::arguments($table), $subfolder);
			}
		}
	}

	/**
	 * Generate model for a database table.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine fromdb:model <table_name,table_name...>
	 */
	public static function model($tables = '')
	{
		// do we have any tables defined?
		if (empty($tables))
		{
			// fetch them all if possible
			$tables = static::fetch_tables('a model');
		}
		else
		{
			// make sure we have an array to work with
			is_array($tables) or $tables = explode(',', $tables);
		}

		// generate for each table defined
		foreach ($tables as $table)
		{
			// start with an empty list
			\Oil\Generate::$create_files = array();

			// and generate
			call_user_func('Oil\Generate::model', static::arguments($table));
		}
	}

	/**
	 * Generate migrations for a database table.
	 *
	 * Usage (from command line):
	 *
	 * php oil refine fromdb:migration <table_name,table_name...>
	 */
	public static function migration($tables = '')
	{
		// do we have any tables defined?
		if (empty($tables))
		{
			// fetch them all if possible
			$tables = static::fetch_tables('a model');
		}
		else
		{
			// make sure we have an array to work with
			is_array($tables) or $tables = explode(',', $tables);
		}

		// generate for each table defined
		foreach ($tables as $table)
		{
			// start with an empty list
			\Oil\Generate::$create_files = array();

			// and generate
			call_user_func('Oil\Generate::migration', static::arguments($table, 'migration'));
		}
	}

	/**
	 * Construct the list of tables
	 *
	 * @param  string  type of action being called, used to display a customized message
	 * @return  array
	 */
	protected static function fetch_tables($type)
	{
		// do we want to generate for all tables?
		if ( ! \Cli::option('all', false))
		{
			\Cli::write('No table names specified to run '.$type.' on.', 'red');
			exit();
		}

		// get the list of all available tables
		try
		{
			$list = \DB::list_tables(null, \Cli::option('db', null));
		}
		catch (\sFuelException $e)
		{
			\Cli::write('The database driver configured does not support listing tables. Please specify them manually.', 'red');
			exit();
		}

		$prefix = \DB::table_prefix();
		$migration = \Config::get('migrations.table', 'migration');

		$tables = array();

		// create the table list
		foreach ($list as $table)
		{
			// strip any defined table prefix from the table name
			if ( ! empty($prefix) and strpos($table, $prefix) === 0)
			{
				$table = substr($table, strlen($prefix));
			}

			// skip the migration table
			if ($table !== $migration)
			{
				$tables[] = $table;
			}
		}

		return $tables;
	}

	/**
	 * Construct the argument list
	 *
	 * @param  string  $table      name of the database table we need to create the list for
	 * @param  string  $migration  type of file we're generating an arguments list for
	 * @return array
	 */
	protected static function arguments($table, $type = 'model')
	{
		// construct the arguments list, starting with the table name
		switch ($type)
		{
			case 'migration':
				$arguments = array('create_'.$table);
				break;

			case 'model':
			default:
				$arguments = array(\Inflector::singularize($table));
		}

		// set some switches
		$include_timestamps = false;
		$timestamp_is_int = true;

		// get the list of columns from the table
		try
		{
			$columns = \DB::list_columns(trim($table), null, \Cli::option('db', null));
		}
		catch (\Exception $e)
		{
			\Cli::write($e->getMessage(), 'red');
			exit();
		}

		// get the list of indexes from the table
		try
		{
			$indexes = \DB::list_indexes(trim($table), null, \Cli::option('db', null));
		}
		catch (\Exception $e)
		{
			\Cli::write($e->getMessage(), 'red');
			exit();
		}

		// process the columns found
		foreach ($columns as $column)
		{
			// do we have a data_type defined? If not, use the generic type
			isset($column['data_type']) or $column['data_type'] = $column['type'];

			// detect timestamp columns
			if (in_array($column['name'], array('created_at', 'updated_at')))
			{
				$include_timestamps = true;
				$timestamp_is_int = $column['data_type'] == 'int';
				continue;
			}

			// do we need to add constraints?
			$constraint = '';
			foreach (array('length', 'character_maximum_length', 'display') as $idx)
			{
				// check if we have such a column, and filter out some default values
				if (isset($column[$idx]) and ! in_array($column[$idx], array('65535', '4294967295')))
				{
					$constraint = $column[$idx];
					break;
				}
			}
			// if it's an enum column, list the available options
			if (in_array($column['data_type'], array('set', 'enum')))
			{
					$constraint = implode(',', $column['options']);
			}

			// store the constraint
			$column['constraint'] = $constraint;

			// fetch index information
			$column['indexes'] = array();
			foreach ($indexes as $index)
			{
				// check if we have an index on the current column
				if ($column['name'] == $index['column'])
				{
					// add the index details for this field
					$column['indexes'][$index['name']] = $index;
				}
			}

			// store the column in the argument list
			$arguments[$column['name']] = $column;
		}

		// tell oil not to fiddle with column information
		\Cli::set_option('no-standardisation', true);

		// return the generated argument list
		return $arguments;
	}
}
