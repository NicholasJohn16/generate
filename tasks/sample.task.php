<?php

if (!$console->isInitialized()) {
    return;
}

$console->add(new GenerateSampleCommand);

require_once COMPOSER_VENDOR_DIR . '/fzaninotto/faker/src/autoload.php';

use \Symfony\Component\Console\Command\Command;
use \Symfony\Component\Console\Input\InputOption;
use \Symfony\Component\Console\Input\InputArgument;
use \Symfony\Component\Console\Input\InputInterface;
use \Symfony\Component\Console\Input\InputDefinition;
use \Symfony\Component\Console\Output\OutputInterface;

class GenerateSampleCommand extends Command
{
	private $repo;
	private $faker;
	private $config;

	public function __construct() {
		$configJson = file_get_contents(dirname(__FILE__) . '/config.json');
		$config = json_decode($configJson);

		if(file_exists(COMPOSER_ROOT . '/sample.json')) {
			$overrideJson = file_get_contents(COMPOSER_ROOT . '/sample.json');
			$override = json_decode($overrideJson);
			$config = $this->object_merge($config, $override);
		}

		$this->config = $config;
		$this->faker = Faker\Factory::create();

		parent::__construct();
	}

	protected function configure()
	{
		$this
			->setName('generate:sample')
			->setDescription('Generates sample data for provided entity')
			->setDefinition(array(
				new InputArgument('component.entity', InputArgument::REQUIRED, 'Repo and entity to generate'),
				new InputArgument('relationships', InputArgument::IS_ARRAY, 'Relationships for entities'), // followers:id 
				new InputOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of entities to generate', 10)
			));
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->getApplication()->loadFramework();
		list($component, $entity) = explode('.', $input->getArgument('component.entity'));
		$relationships = $this->prepareRelationship($input->getArgument('relationships'));
		$data = array();
		$attributes = array();

		try {
			$this->repo = KService::get('repos:'.$input->getArgument('component.entity'));
		} catch (Exception $e) {
			$output->writeln($e->getMessage());
			return false;
		}

		$identity = $this->repo->getDescription()->getIdentityProperty();
		$config = $this->getConfig();

		for ($i = 0; $i < $input->getOption('count'); $i++) {
			$entity = $this->repo->getEntity();
			
			foreach ($this->repo->getDescription()->getAttributes() as $attribute) {
				if($attribute == $identity) { continue; }

				$attributeName = $attribute->getName();
				if(property_exists($config, $attributeName)) {
					if($value = $this->getValue($attribute, $config->$attributeName)) {
						$data[$attributeName] = $value;
					}
				} else {
					// $output->writeln('Attribute '.$attribute->getName().' skipped. Not found in config.', OutputInterface::VERBOSITY_VERBOSE);
				}
			}

			foreach ($this->repo->getDescription()->getRelationships() as $name => $relationship) {
				$index = array_search($relationship->getName(), array_column($relationships, 'name'));

				if($index !== false) {
					$relationshipInput = $relationships[$index];

					if($relationship->isManyToOne()) {
						$parentRepo = $relationship->getParentRepository();

						if(array_key_exists('int', $relationshipInput)) {
							$child = $parentRepo->fetch($relationshipInput['int']);
						} else {
							$child = $parentRepo->getQuery()->order('RAND()')->fetch();
						}

						$entity->set($relationship->getName(), $child);
					}

					if($relationship->isManyToMany()) {
						$output->writeln('ManyToMany relationships not yet supported.');
					}
				}

			}

			$result = $entity->setData($data)->save();

			if(!$result) {
				$errors = $entity->getErrors();
				foreach ($errors as $error) {
					$output->writeln(print_r($error, true));
				}
			}
		}
	}

	/**
	 * Formats the relationship input into key value pairs
	 * @param  string $inputs relationship:id or relationships:count
	 * @return array         array with name key and possible int value
	 */
	private function prepareRelationship($inputs) 
	{
		$relationships = array();

		foreach($inputs as $input) {
			$relationship = array();
			$relationship['name'] = $input;

			if(strpos($input, ':')) {
				list($relationship['name'], $relationship['int']) = explode(':', $input);
			}

			$relationships[] = $relationship;
		}

		return $relationships;
	}

	private function getValue($attribute, $config) 
	{
		if(property_exists($config, 'skip') && $config->skip) {
			return;
		}

		$generator = $this->faker;
		$defaults = $this->getDefaultsForType($attribute->getType());
		$config = $this->object_merge($defaults, $config);

		$provider = $config->provider;

		if(!$attribute->isRequired()) {
			$generator = $this->faker->optional();
		}

		if($provider == 'userName') {
			$generator = $generator->valid(array($this, 'validateUsername'));
		}

		if(property_exists($config, 'arguments')) {
			$value = $generator->$provider(...$config->arguments);
		} else {
			$value = $generator->$provider();
		}

		if($value && $attribute->getType() == 'AnDomainAttributeDate') {
			$value = AnDomainAttributeDate::getInstance()->setDate($value->getTimestamp(), DATE_FORMAT_UNIXTIME);
		}

		if(is_null($value)) {
			$value = $attribute->getDefaultValue();
		}

		return $value;
	}

	/**
	 * Gets default values for attribute by type
	 * @param  string $type string value for type
	 * @return object       stdClass 
	 */
	private function getDefaultsForType($type) {
		$defaults = $this->config->defaults;
		return $defaults->$type;
	}

	protected function getConfig() 
	{
		$config = new stdClass();

		if($this->repo->entityInherits('ComBaseDomainEntityNode')) {
			$config = $this->object_merge($config, $this->config->base->node);
		}

		if($this->repo->entityInherits('ComBaseDomainEntityComment')) {
			$config = $this->object_merge($config, $this->config->base->comment);
		}

		if($this->repo->entityInherits('ComMediumDomainEntityMedium')) {
			$config = $this->object_merge($config, $this->config->medium->medium);
		}

		if($this->repo->entityInherits('ComActorsDomainEntityActor')) {
			$config = $this->object_merge($config, $this->config->actors->actor);
		}

		$package = $this->repo->getIdentifier()->package;
		$name = $this->repo->getIdentifier()->name;
		if(
			property_exists($this->config, $package) && 
			property_exists($this->config->$package, $name)
		) {
			$config = $this->object_merge($config, $this->config->$package->$name);
		}

		return $config;
	}


	/**
	 * Merges two objects where the properties of the first are overridden by the second
	 * @param  object|array $object1 
	 * @param  object|array $object2 
	 * @return object
	 */
	protected function object_merge($object1, $object2) 
	{
		return (object) array_merge((array) $object1, (array) $object2);
	}

	public function validateUsername($string) {
		$regex = '/^[A-Za-z][A-Za-z0-9_-]*$/';
		return preg_match($regex, $string);
	}

}

 ?>