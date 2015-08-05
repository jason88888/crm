<?php

namespace OroCRM\Bundle\MagentoBundle\Entity\Manager;

use Oro\Bundle\SoapBundle\Entity\Manager\ApiEntityManager;

class CustomerApiEntityManager extends ApiEntityManager
{
    /**
     * @return array
     */
    protected function getSerializationConfig()
    {
        return [
            'excluded_fields' => ['carts', 'orders', 'newsletterSubscriber'],
            'fields'          => [
                'birthday'     => [
                    'data_transformer' => 'orocrm_magento.customer_birthday_type_transformer'
                ],
                'website'      => ['fields' => 'id'],
                'store'        => ['fields' => 'id'],
                'group'        => ['fields' => 'id'],
                'contact'      => ['fields' => 'id'],
                'account'      => ['fields' => 'id'],
                'dataChannel'  => ['fields' => 'id'],
                'channel'      => ['fields' => 'id'],
                'owner'        => ['fields' => 'id'],
                'organization' => ['fields' => 'id'],
                'addresses'    => $this->getAddressSerializationConfig()
            ]
        ];
    }

    /**
     * @return array
     */
    protected function getAddressSerializationConfig()
    {
        return [
            'excluded_fields' => ['newsletterSubscriber'],
            'fields'          => [
                'country' => ['fields' => 'iso2Code'],
                'region'  => ['fields' => 'combinedCode'],
                'owner'   => ['fields' => 'id'],
                'created' => ['fields' => 'date'],
                'updated' => ['fields' => 'date'],
                'types'   => ['fields' => 'name'],
                'channel' => ['fields' => 'id'],
            ]
        ];
    }
}
