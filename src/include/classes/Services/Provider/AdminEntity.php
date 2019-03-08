<?php
/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 


/**
 * This class is used to reperesent call Entities the admin interface can  list/edit/delete
 *
 * @package core
*/
class AdminEntity {

	private $sType;
	private $aFields = [];
	private $aData = [];
	private $fComputeId;
	private $sIdField;

	static function createCollection(string $sType, array $aFields, array $aRows, callable $fComputeId=null, string $sIdField=null): array
	{
		$aReturn = [];
		foreach($aRows as $aRow){
			$aReturn[] = new AdminEntity($sType, $aFields, $aRow, $fComputeId, $sIdField);
		}
		return $aReturn;
	}

	public function __construct(string $sType, array $aFields, array $aData, callable $fComputeId=null, string $sIdField=null)
	{
		$this->sType = $sType;
		$this->aFields = $aFields;
		$this->aData = $aData;
		$this->fComputeId = $fComputeId;
		$this->sIdField = $sIdField;
	}

	public function getFields(): array
	{
		return $this->aFields;
	}

	public function getValue(string $sField)
	{
		if(array_key_exists($sField, $this->aData)){
			return $this->aData[$sField];
		}
	}

	public function getId()
	{
		if(is_callable($this->fComputeId)){
			return ($this->fComputeId)($this->aData);
		}
		if(!is_null($this->sIdField) AND array_key_exists($this->sIdField,$this->aData)){
			return $this->aData[$this->sIdField];
		}
	}
}
