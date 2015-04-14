<?php

namespace OroCRM\Bundle\MagentoBundle\ImportExport\Strategy;

use OroCRM\Bundle\MagentoBundle\Entity\Address;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;

class CustomerStrategy extends AbstractImportStrategy
{
    /**
     * @var Address[]
     */
    protected $importingAddresses = [];

    /**
     * @var array
     */
    protected $addressRegions = [];

    /**
     * @param Customer $entity
     * @return Customer
     */
    protected function beforeProcessEntity($entity)
    {
        $this->importingAddresses = [];
        $this->addressRegions = [];
        $importingAddresses = $entity->getAddresses();
        if ($importingAddresses) {
            foreach ($importingAddresses as $address) {
                $originId = $address->getOriginId();
                $this->importingAddresses[$originId] = $address;

                if ($address->getRegion()) {
                    $this->addressRegions[$originId] = $address->getRegion()->getCombinedCode();
                } else {
                    $this->addressRegions[$originId] = null;
                }
            }
        }

        return parent::beforeProcessEntity($entity);
    }

    /**
     * @param Customer $entity
     * @return Customer
     */
    protected function afterProcessEntity($entity)
    {
        $this->processAddresses($entity);

        return parent::afterProcessEntity($entity);
    }

    /**
     * @param Customer $entity
     */
    protected function processAddresses(Customer $entity)
    {
        if (!$entity->getAddresses()->isEmpty()) {
            foreach ($entity->getAddresses() as $address) {
                $originId = $address->getOriginId();
                if (array_key_exists($originId, $this->importingAddresses)) {
                    $remoteAddress = $this->importingAddresses[$originId];
                    $this->addressHelper->mergeAddressTypes($address, $remoteAddress);

                    if (!empty($this->addressRegions[$originId]) && $address->getCountry()) {
                        $this->addressHelper->updateRegionByMagentoRegionId(
                            $address,
                            $address->getCountry()->getIso2Code(),
                            $this->addressRegions[$originId]
                        );
                    }
                }
            }
        }
    }
}
