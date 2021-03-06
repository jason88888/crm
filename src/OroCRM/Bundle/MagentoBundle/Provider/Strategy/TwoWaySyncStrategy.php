<?php

namespace OroCRM\Bundle\MagentoBundle\Provider\Strategy;

use Akeneo\Bundle\BatchBundle\Entity\StepExecution;

use Symfony\Component\PropertyAccess\PropertyAccess;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\IntegrationBundle\ImportExport\Processor\StepExecutionAwareExportProcessor;
use Oro\Bundle\IntegrationBundle\ImportExport\Processor\StepExecutionAwareImportProcessor;
use Oro\Bundle\IntegrationBundle\Provider\TwoWaySyncConnectorInterface;

class TwoWaySyncStrategy implements TwoWaySyncStrategyInterface
{
    /** @var array */
    protected $supportedStrategies = [
        TwoWaySyncConnectorInterface::REMOTE_WINS,
        TwoWaySyncConnectorInterface::LOCAL_WINS
    ];

    /** @var StepExecutionAwareImportProcessor */
    protected $importProcessor;

    /** @var StepExecutionAwareExportProcessor */
    protected $exportProcessor;

    /** @var DoctrineHelper */
    protected $doctrineHelper;

    /** @var object|null */
    protected $normalizedObject;

    /**
     * @param StepExecutionAwareImportProcessor $importProcessor
     * @param StepExecutionAwareExportProcessor $exportProcessor
     * @param DoctrineHelper $doctrineHelper
     */
    public function __construct(
        StepExecutionAwareImportProcessor $importProcessor,
        StepExecutionAwareExportProcessor $exportProcessor,
        DoctrineHelper $doctrineHelper
    ) {
        $this->importProcessor = $importProcessor;
        $this->exportProcessor = $exportProcessor;
        $this->doctrineHelper = $doctrineHelper;
    }

    /**
     * @param StepExecution $stepExecution
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->importProcessor->setStepExecution($stepExecution);
        $this->exportProcessor->setStepExecution($stepExecution);
    }

    /**
     * {@inheritdoc}
     */
    public function merge(
        array $changeSet,
        array $localData,
        array $remoteData,
        $strategy = TwoWaySyncConnectorInterface::REMOTE_WINS,
        array $additionalFields = []
    ) {
        if (!in_array($strategy, $this->supportedStrategies, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Strategy "%s" is not supported, expected one of "%s"',
                    $strategy,
                    implode(',', $this->supportedStrategies)
                )
            );
        }

        $remoteData = $this->normalize($remoteData);
        if (!$changeSet) {
            $this->refreshNormalizedObject();

            return $remoteData;
        }

        $oldValues = $this->getChangeSetValues($changeSet, 'old');
        $oldValues = $this->fillEmptyValues($oldValues, $this->getChangeSetValues($changeSet, 'new'));
        $snapshot = $this->getSnapshot($localData, $oldValues);
        $localChanges = $this->getDiff($localData, $snapshot);
        $remoteChanges = $this->getDiff($remoteData, $snapshot);
        $conflicts = array_keys(array_intersect_key($remoteChanges, $localChanges));

        foreach (array_merge($conflicts, $additionalFields) as $conflict) {
            if (!array_key_exists($conflict, $remoteData)) {
                continue;
            }

            if (!array_key_exists($conflict, $localData)) {
                continue;
            }

            if ($strategy === TwoWaySyncConnectorInterface::LOCAL_WINS) {
                $remoteData[$conflict] = $localData[$conflict];
            }
        }

        $localDataForUpdate = array_diff_key(array_keys($localChanges), $conflicts);
        foreach ($localDataForUpdate as $property) {
            $remoteData[$property] = $localData[$property];
        }

        $this->refreshNormalizedObject();

        return $remoteData;
    }

    /**
     * @param array $baseData
     * @param array $newData
     * @return array
     */
    protected function getDiff(array $baseData, array $newData)
    {
        $array = [];

        foreach ($baseData as $baseKey => $baseValue) {
            if (array_key_exists($baseKey, $newData)) {
                if (is_array($baseValue)) {
                    $diff = $this->getDiff($baseValue, $newData[$baseKey]);
                    if (count($diff)) {
                        $array[$baseKey] = $diff;
                    }
                } elseif ($baseValue != $newData[$baseKey]) {
                    $array[$baseKey] = $baseValue;
                }
            } else {
                $array[$baseKey] = $baseValue;
            }
        }

        return $array;
    }

    /**
     * @param array $localData
     * @param array $oldValues
     * @return array
     */
    protected function getSnapshot(array $localData, array $oldValues)
    {
        $object = $this->importProcessor->process($localData);

        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        foreach ($oldValues as $propertyName => $value) {
            $propertyAccessor->setValue($object, $propertyName, $value);
        }

        return $this->exportProcessor->process($object);
    }

    /**
     * @param array $data
     * @return array
     */
    protected function normalize(array $data)
    {
        $this->normalizedObject = $this->importProcessor->process($data);

        return $this->exportProcessor->process($this->normalizedObject);
    }

    /**
     * Since object normalization does unwanted changes to the object,
     * it's necessary to revert it into original value
     *
     * This can be reproduced by refreshing page after changing magento customer address (e.g. first name)
     * with enabled 2 way sync.
     */
    protected function refreshNormalizedObject()
    {
        if (!$this->normalizedObject) {
            return;
        }

        $this->doctrineHelper->refreshIncludingUnitializedRelations($this->normalizedObject);

        $this->normalizedObject = null;
    }

    /**
     * @param array $oldValues
     * @param array $newValues
     * @return array
     */
    protected function fillEmptyValues(array $oldValues, array $newValues)
    {
        $keysToCheck = array_keys($newValues);
        foreach ($keysToCheck as $key) {
            if (!array_key_exists($key, $oldValues)) {
                $oldValues[$key] = null;
            }
        }

        return $oldValues;
    }

    /**
     * @param array $changeSet
     * @param string $key
     * @return array
     */
    protected function getChangeSetValues($changeSet, $key)
    {
        $values = array_map(
            function ($data) use ($key) {
                if (empty($data[$key])) {
                    return null;
                }

                return $data[$key];
            },
            $changeSet
        );

        return array_filter($values);
    }
}
