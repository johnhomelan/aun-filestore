<?php

/**
 * This file contains the class the implements the IPv4 host interface logic
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\ProviderInterface;

class Interfaces
{


	public function __construct(private readonly ProviderInterface $oProvider)
 	{
	 }

}
