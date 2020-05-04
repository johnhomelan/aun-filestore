<?php
/**
 * This file contains the Interface all service provers that have an admin interface
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 


/**
 * This class is the interface all services must provide 
 *
 * @package core
*/
interface AdminInterface {

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string; 

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string; 

	/**
	  * Tests if the service provider is disabled 
	  *
	 */  
	public function isDisabled(): bool;

	/**
	 * Sets the service disabled 
	 *
	*/ 
	public function setDisabled(): void;

	/**
	 * Enables the service 
	 *
	*/
	public function setEnabled(): void;

	/**
	 * Gets a human readable status string for the services
	 *
	*/ 
	public function getStatus(): string;

	/**
	 * Gets a list of all the entity type for this service provider
	 *
	*/ 
	public function getEntityTypes(): array;

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array;

	/**
	 * Gets the entity instances of a given type for this service 
	 *
	*/
	public function getEntities(string $sType): array;

	/**
	 * Gets the commands that can be run
	 *
	*/
	public function getCommands(): array;

}
