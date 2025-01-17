<?php
/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 *
 * This software is not allowed to be used in Russia and Belarus.
 */

declare(strict_types=1);

namespace Pim\Repositories;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Json;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Util;

/**
 * Class Attribute
 */
class Attribute extends AbstractRepository
{
    /**
     * @var string
     */
    protected $ownership = 'fromAttribute';

    /**
     * @var string
     */
    protected $ownershipRelation = 'ProductAttributeValue';

    /**
     * @var string
     */
    protected $assignedUserOwnership = 'assignedUserAttributeOwnership';

    /**
     * @var string
     */
    protected $ownerUserOwnership = 'ownerUserAttributeOwnership';

    /**
     * @var string
     */
    protected $teamsOwnership = 'teamsAttributeOwnership';

    protected function init()
    {
        parent::init();

        $this->addDependency('language');
        $this->addDependency('dataManager');
    }

    public function clearCache(): void
    {
        $this->getInjection('dataManager')->clearCache();
    }

    public function prepareTypeValueIds(Entity $entity): void
    {
        if (!in_array($entity->get('type'), ['enum', 'multiEnum'])) {
            return;
        }

        if (!(empty($entity->get('typeValueIds')) && !empty($entity->get('typeValue')))) {
            return;
        }

        $typeValueIds = array_map('strval', array_keys($entity->get('typeValue')));
        $entity->set('typeValueIds', $typeValueIds);

        $this->getPDO()->exec("UPDATE `attribute` SET type_value_ids='" . Json::encode($typeValueIds) . "' WHERE id='{$entity->get('id')}'");
    }

    public function updateSortOrderInAttributeGroup(array $ids): void
    {
        foreach ($ids as $k => $id) {
            $id = $this->getPDO()->quote($id);
            $sortOrder = $k * 10;
            $this->getPDO()->exec("UPDATE `attribute` SET sort_order_in_attribute_group=$sortOrder WHERE id=$id");
        }
    }

    /**
     * @inheritDoc
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options = [])
    {
        if (!$this->isTypeValueValid($entity)) {
            throw new BadRequest("The number of 'Values' items should be identical for all locales");
        }

        if (!$entity->isNew()) {
            $this->updateEnumPav($entity);
            $this->updateMultiEnumPav($entity);
        }

        // set sort order
        if (is_null($entity->get('sortOrderInAttributeGroup'))) {
            $entity->set('sortOrderInAttributeGroup', (int)$this->max('sortOrderInAttributeGroup') + 1);
        }

        if (!$entity->isNew() && $entity->isAttributeChanged('unique') && $entity->get('unique')) {
            $query = "SELECT COUNT(*) 
                      FROM product_attribute_value 
                      WHERE attribute_id='{$entity->id}' 
                        AND deleted=0 %s 
                      GROUP BY %s, `language`, scope, channel_id HAVING COUNT(*) > 1";
            switch ($entity->get('type')) {
                case 'unit':
                case 'currency':
                    $query = sprintf($query, 'AND float_value IS NOT NULL AND varchar_value IS NOT NULL', 'float_value, varchar_value');
                    break;
                case 'float':
                    $query = sprintf($query, 'AND float_value IS NOT NULL', 'float_value');
                    break;
                case 'int':
                    $query = sprintf($query, 'AND int_value IS NOT NULL', 'int_value');
                case 'date':
                    $query = sprintf($query, 'AND date_value IS NOT NULL', 'date_value');
                case 'datetime':
                    $query = sprintf($query, 'AND datetime_value IS NOT NULL', 'datetime_value');
                    break;
                default:
                    $query = sprintf($query, 'AND varchar_value IS NOT NULL', 'varchar_value');
                    break;
            }

            if (!empty($this->getPDO()->query($query)->fetch(\PDO::FETCH_ASSOC))) {
                throw new Error($this->exception('attributeNotHaveUniqueValue'));
            }
        }

        if (!$entity->isNew() && $entity->isAttributeChanged('pattern') && !empty($pattern = $entity->get('pattern'))) {
            if (!preg_match("/^\/(.*)\/$/", $pattern)) {
                throw new BadRequest($this->getInjection('language')->translate('regexNotValid', 'exceptions', 'FieldManager'));
            }

            $query = "SELECT DISTINCT varchar_value
                      FROM product_attribute_value 
                      WHERE deleted=0 
                        AND attribute_id='{$entity->get('id')}'
                        AND varchar_value IS NOT NULL 
                        AND varchar_value!=''";

            foreach ($this->getPDO()->query($query)->fetchAll(\PDO::FETCH_COLUMN) as $value) {
                if (!preg_match($pattern, $value)) {
                    throw new BadRequest($this->exception('someAttributeDontMathToPattern'));
                }
            }
        }

        // call parent action
        parent::beforeSave($entity, $options);
    }

    /**
     * @inheritDoc
     */
    protected function afterSave(Entity $entity, array $options = array())
    {
        if ($entity->isAttributeChanged('virtualProductField') || (!empty($entity->get('virtualProductField') && $entity->isAttributeChanged('code')))) {
            $this->clearCache();
        }

        parent::afterSave($entity, $options);

        if (!$entity->isNew() && $entity->isAttributeChanged('isMultilang')) {
            $this
                ->getEntityManager()
                ->getRepository('Product')
                ->updateProductsAttributes("SELECT product_id FROM `product_attribute_value` WHERE attribute_id='{$entity->get('id')}' AND deleted=0", true);
        }

        $this->setInheritedOwnership($entity);
    }

    protected function afterRemove(Entity $entity, array $options = [])
    {
        if (!empty($entity->get('virtualProductField'))) {
            $this->clearCache();
        }

        parent::afterRemove($entity, $options);
    }

    /**
     * @inheritDoc
     */
    public function max($field)
    {
        $data = $this
            ->getPDO()
            ->query("SELECT MAX(sort_order_in_attribute_group) AS max FROM attribute WHERE deleted=0")
            ->fetch(\PDO::FETCH_ASSOC);

        return $data['max'];
    }

    /**
     * @inheritdoc
     */
    protected function beforeUnrelate(Entity $entity, $relationName, $foreign, array $options = [])
    {
        if ($relationName == 'products') {
            // prepare data
            $attributeId = (string)$entity->get('id');
            $productId = (is_string($foreign)) ? $foreign : (string)$foreign->get('id');

            if ($this->isProductFamilyAttribute($attributeId, $productId)) {
                throw new Error($this->exception("youCanNotUnlinkProductFamilyAttribute"));
            }
        }
    }

    protected function updateEnumPav(Entity $attribute): void
    {
        if ($attribute->get('type') != 'enum') {
            return;
        }

        if (!$this->isEnumTypeValueValid($attribute)) {
            return;
        }

        if (empty($attribute->getFetched('typeValueIds'))) {
            return;
        }

        // prepare became values
        $becameValues = [];
        foreach ($attribute->get('typeValueIds') as $k => $v) {
            foreach ($attribute->getFetched('typeValueIds') as $k1 => $v1) {
                if ($v1 === $v) {
                    $becameValues[$attribute->getFetched('typeValue')[$k1]] = $attribute->get('typeValue')[$k];
                }
            }
        }

        $pavs = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->select(['id', 'language', 'varcharValue'])
            ->where(['attributeId' => $attribute->get('id'), 'language' => 'main'])
            ->find()
            ->toArray();

        foreach ($pavs as $pav) {
            $value = !empty($becameValues[$pav['varcharValue']]) ? $this->getPDO()->quote($becameValues[$pav['varcharValue']]) : 'NULL';
            $this->getPDO()->exec("UPDATE product_attribute_value SET varchar_value=$value WHERE id='{$pav['id']}'");
            $this->getPDO()->exec("UPDATE product_attribute_value SET varchar_value=NULL WHERE main_language_id='{$pav['id']}'");
        }
    }

    protected function updateMultiEnumPav(Entity $attribute): void
    {
        if ($attribute->get('type') != 'multiEnum') {
            return;
        }

        if (!$this->isEnumTypeValueValid($attribute)) {
            return;
        }

        if (empty($attribute->getFetched('typeValueIds'))) {
            return;
        }

        // prepare became values
        $becameValues = [];
        foreach ($attribute->get('typeValueIds') as $k => $v) {
            foreach ($attribute->getFetched('typeValueIds') as $k1 => $v1) {
                if ($v1 === $v) {
                    $becameValues[$attribute->getFetched('typeValue')[$k1]] = $attribute->get('typeValue')[$k];
                }
            }
        }

        /** @var array $pavs */
        $pavs = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->select(['id', 'language', 'textValue'])
            ->where(['attributeId' => $attribute->get('id'), 'language' => 'main'])
            ->find()
            ->toArray();

        foreach ($pavs as $pav) {
            $values = [];
            if (!empty($pav['textValue'])) {
                $jsonData = @json_decode($pav['textValue'], true);
                if (!empty($jsonData)) {
                    $values = $jsonData;
                }
            }

            if (!empty($values)) {
                $newValues = [];
                foreach ($values as $value) {
                    if (isset($becameValues[$value])) {
                        $newValues[] = $becameValues[$value];
                    }
                }
                $pav['textValue'] = str_replace(["'", '\"'], ["\'", '\\\"'], Json::encode($newValues, JSON_UNESCAPED_UNICODE));
            }

            $this->getPDO()->exec("UPDATE product_attribute_value SET text_value='{$pav['textValue']}' WHERE id='{$pav['id']}'");
            $this->getPDO()->exec("UPDATE product_attribute_value SET text_value=NULL WHERE main_language_id='{$pav['id']}'");
        }
    }

    /**
     * @param $entity
     *
     * @return bool
     * @throws BadRequest
     */
    protected function isEnumTypeValueValid($entity): bool
    {
        if (!empty($entity->get('typeValue'))) {
            foreach (array_count_values($entity->get('typeValue')) as $count) {
                if ($count > 1) {
                    throw new BadRequest($this->exception('attributeValueShouldBeUnique'));
                }
            }
        }

        return true;
    }

    /**
     * @param string $attributeId
     * @param string $productId
     *
     * @return bool
     */
    protected function isProductFamilyAttribute(string $attributeId, string $productId): bool
    {
        $value = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->select(['id'])
            ->where(['attributeId' => $attributeId, 'productId' => $productId, 'productFamilyId !=' => null])
            ->findOne();

        return !empty($value);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->getInjection('language')->translate($key, "exceptions", "Attribute");
    }

    /**
     * @param Entity $entity
     *
     * @return bool
     */
    protected function isTypeValueValid(Entity $entity): bool
    {
        if (!empty($entity->get('isMultilang')) && $this->getConfig()->get('isMultilangActive', false) && in_array($entity->get('type'), ['enum', 'multiEnum'])) {
            $count = count($entity->get('typeValue'));
            foreach ($this->getConfig()->get('inputLanguageList', []) as $locale) {
                $field = 'typeValue' . ucfirst(Util::toCamelCase(strtolower($locale)));
                if (count($entity->get($field)) != $count) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function getUnitFieldMeasure(string $fieldName, Entity $entity): string
    {
        if ($fieldName === 'unitDefault') {
            $typeValue = $entity->get('typeValue');

            return empty($typeValue) ? '' : array_shift($typeValue);
        }

        return parent::getUnitFieldMeasure($fieldName, $entity);
    }
}
