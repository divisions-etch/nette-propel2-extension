<?php

namespace Divisions\Nette\Extension\Propel;

use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Config;
use Propel\Runtime\Exception\UnexpectedValueException;

/**
 * Class Propel2Extension
 * @package Divisions\Nette\Extension\Propel
 */
class Propel2Extension
	extends CompilerExtension{

	/**
	 * @var array
	 */
	private $defaultConnectionValues = ['user'     => 'root',
	                                    'password' => null,
	                                    'dsn'      => null];

	/**
	 * @var array
	 */
	private $defaultLoggerValues = ['type'     => 'tracy',
	                                'level'    => null,
	                                'facility' => null,
	                                'bubble'   => null,
	                                'maxFiles' => null];

	/**
	 * @var array
	 */
	private $supportedHandlers = ['Divisions\Monolog\Handler\Tracy\PropelPanel' => 'tracy',
	                              'Monolog\Handler\NullHandler'                 => 'null',
	                              'Monolog\Handler\StreamHandler'               => 'stream',
	                              'Monolog\Handler\RotatingFileHandler'         => 'rotating_file',
	                              'Monolog\Handler\SyslogHandler'               => 'syslog'];

	/**
	 * @var bool
	 */
	private $init = true;

	/**
	 * @var array
	 */
	private $panels = [];

	/**
	 * @throws UnexpectedValueException
	 */
	public function loadConfiguration(){

		$config = $this->getConfig();

		if(empty($config['database']['connections'])){
			$this->init = false;

			return;
		}

		$this->checkConfig($config);

		$parsedConfig = $this->parseConfig($config);

		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('container'))
		        ->setClass('Propel\Runtime\ServiceContainer\StandardServiceContainer')
		        ->setFactory('Propel\Runtime\Propel::getServiceContainer');

		$this->registerAdapters($parsedConfig['adapters']);

		$this->registerConnections($parsedConfig['connections']);

		$this->registerLoggers($parsedConfig['loggers']);
	}

	/**
	 * @param $config
	 *
	 * @throws UnexpectedValueException
	 */
	private function checkConfig(&$config){
		foreach($config['database']['connections'] AS $name => $values){
			if(!array_key_exists('adapter', $values)){
				throw new UnexpectedValueException('Propel driver must be specified. Check your configuration file.');
			}
		}

		if(empty($config['runtime']['log'])){
			$config['runtime']['log'] = [];

			return;
		}

		foreach($config['runtime']['log'] AS $name => $values){
			if(!empty($values['type']) AND !in_array($values['type'], $this->supportedHandlers)){
				throw new UnexpectedValueException('Unsupported log handler "'.$values['type'].'". Check your configuration file.');
			}elseif(in_array($values['type'], ['stream', 'rotating_file']) AND empty(trim($values['path']))){
				throw new UnexpectedValueException('The parameter "'.$this->prefix('runtime.log.'.$name.'.path').'" must be defined. Check your configuration file.');
			}elseif(in_array($values['type'],
			                 ['stream',
			                  'rotating_file']) AND !is_writable(dirname($values['path'])) AND !in_array($values['path'],
			                                                                                             ['php://stderr',
			                                                                                              'php://stdout'])
			){
				throw new UnexpectedValueException('Path "'.$values['path'].'" is not writable. Check your configuration file. Section "'.$this->prefix('runtime.log.'.$name.'.path').'"');
			}
		}
	}

	/**
	 * @param $config
	 *
	 * @return mixed
	 */
	private function parseConfig($config){
		$parsedConfig['connections'] = $parsedConfig['adapters'] = $parsedConfig['loggers'] = [];

		foreach($config['database']['connections'] AS $name => $values){
			$parsedConfig['connections'][$name] = $values + $this->defaultConnectionValues;
			$parsedConfig['adapters'][$name]    = $values['adapter'];
		}

		foreach($config['runtime']['log'] AS $name => $values){
			$parsedConfig['loggers'][$name] = $values + $this->defaultLoggerValues;
		}

		return $parsedConfig;
	}

	/**
	 * @param array $adapters
	 */
	private function registerAdapters(array $adapters){
		$builder   = $this->getContainerBuilder();
		$container = $builder->getDefinition($this->prefix('container'));
		foreach($adapters AS $name => $adapter){
			$builder->addDefinition($this->prefix($name.'.adapter'))
			        ->setClass('Propel\Runtime\Adapter\AdapterInterface')
			        ->setFactory('Propel\Runtime\Adapter\AdapterFactory::create', [$adapter]);
		}
		$container->addSetup('setAdapterClasses', [$adapters]);
	}


	/**
	 * @param array $connections
	 */
	private function registerConnections(array $connections){
		$builder   = $this->getContainerBuilder();
		$container = $builder->getDefinition($this->prefix('container'));
		foreach($connections AS $name => $connection){
			$conn = $builder->addDefinition($this->prefix($name.'.connection'))
			                ->setClass('Propel\Runtime\Connection\ConnectionInterface')
			                ->setFactory('Propel\Runtime\Connection\ConnectionFactory::create',
			                             [$connection, $this->prefix('@'.$name.'.adapter')]);

			if(isset($connection['useDebug']) AND $connection['useDebug'] === true){
				$conn->addSetup('useDebug', [true]);
			}
			$container->addSetup('setConnection', [$name, $this->prefix('@'.$name.'.connection')]);
		}
	}


	/**
	 * @param array $loggers
	 */
	private function registerLoggers(array $loggers){
		$builder   = $this->getContainerBuilder();
		$container = $builder->getDefinition($this->prefix('container'));

		foreach($loggers AS $name => $values){
			$handlerRegistrationData = $this->getHandlerRegistrationData($name, $values);
			$handler                 = $builder->addDefinition($this->prefix($name.'.logger.handler'));
			$handler->setClass($handlerRegistrationData['handlerClass'], $handlerRegistrationData['constructorValues']);
			$handler->addSetup('setChannelName', [$name]);

			$builder->addDefinition($this->prefix($name.'.logger'))
			        ->setClass('Monolog\Logger', [$name])
			        ->addSetup('pushHandler', [$this->prefix('@'.$name.'.logger.handler')]);


			if($builder->hasDefinition($this->prefix($name.'.connection')) === true){
				$builder->getDefinition($this->prefix($name.'.connection'))
				        ->addSetup('setLogger', [$this->prefix('@'.$name.'.logger')]);
			}else{
				$container->addSetup('setLogger', [$name, $this->prefix('@'.$name.'.logger')]);
			}
		}
	}

	/**
	 * @param       $loggerName
	 * @param array $config
	 *
	 * @return mixed
	 */
	private function getHandlerRegistrationData($loggerName, array $config){
		$data['handlerClass'] = array_search($config['type'], $this->supportedHandlers);

		switch($config['type']){
			case 'tracy':
				$data['constructorValues'] = [$config['level']];
				$this->panels[]            = $loggerName.'.logger.handler';
				break;
			case 'null':
				$data['constructorValues'] = [$config['level']];
				break;
			case 'stream':
				$data['constructorValues'] = [$config['path'], $config['level'], $config['bubble']];
				break;
			case 'rotating_file':
				$data['constructorValues'] = [$config['path'],
				                              $config['maxFiles'],
				                              $config['level'],
				                              $config['bubble']];
				break;
			case 'syslog':
				$data['constructorValues'] = [$config['ident'],
				                              $config['facility'],
				                              $config['level'],
				                              $config['bubble']];
				break;
		}

		return $data;
	}

	/**
	 * @param \Nette\Configurator $config
	 */
	public static function register(\Nette\Configurator $config){
		$config->onCompile[] = function ($config, Compiler $compiler){
			$compiler->addExtension('propel', new Propel2Extension());
		};
	}

	/**
	 * @param \Nette\PhpGenerator\ClassType $class
	 */
	public function afterCompile(\Nette\PhpGenerator\ClassType $class){
		if($this->init !== true){
			return;
		}
		$initialize = $class->methods['initialize'];
		$initialize->addBody('$this->getService(?);', [$this->prefix('container')]);
		foreach($this->panels AS $panel){
			$class->methods['initialize']->addBody('Tracy\Debugger::getBar()->addPanel($this->getService(?));',
			                                       [$this->prefix($panel)]);
		}

	}
}