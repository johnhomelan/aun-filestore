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

	private $fComputeId;

	static function createCollection(string $sType, array $aFields, array $aRows, callable $fComputeId=null, string $sIdField=null): array
	{
		$aReturn = [];
		foreach($aRows as $aRow){
			$aReturn[] = new AdminEntity($sType, $aFields, $aRow, $fComputeId, $sIdField);
		}
		return $aReturn;
	}

	public function __construct(private readonly string $sType, private readonly array $aFields, private readonly array $aData, callable $fComputeId=null, private readonly ?string $sIdField=null)
	{
		$this->fComputeId = $fComputeId;
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
