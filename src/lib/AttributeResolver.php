<?php

/**
 * @copyright Copyright (c) 2018 Carsten Brandt <mail@cebe.cc> and contributors
 * @license https://github.com/cebe/yii2-openapi/blob/master/LICENSE
 */

namespace cebe\yii2openapi\lib;

use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use cebe\openapi\SpecObjectInterface;
use cebe\yii2openapi\lib\exceptions\InvalidDefinitionException;
use cebe\yii2openapi\lib\items\Attribute;
use cebe\yii2openapi\lib\items\AttributeRelation;
use cebe\yii2openapi\lib\items\DbIndex;
use cebe\yii2openapi\lib\items\DbModel;
use cebe\yii2openapi\lib\items\JunctionSchemas;
use cebe\yii2openapi\lib\items\ManyToManyRelation;
use cebe\yii2openapi\lib\openapi\PropertyReader;
use cebe\yii2openapi\lib\openapi\SchemaReader;
use Throwable;
use Yii;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\helpers\StringHelper;
use function explode;
use function in_array;
use function is_string;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;

class AttributeResolver
{
    /**
     * @var Attribute[]|array
     */
    private $attributes = [];

    /**
     * @var AttributeRelation[]|array
     */
    private $relations = [];

    /**
     * @var ManyToManyRelation[]|array
     */
    private $many2many = [];

    /**
     * @var string
     */
    private $schemaName;
    /**
     * @var string
     */
    private $tableName;
    /**
     * @var SchemaReader
     */
    private $schema;
    /**
     * @var \cebe\yii2openapi\lib\items\JunctionSchemas
     */
    private $junctions;

    /**@var bool */
    private $isJunctionSchema;

    /**@var bool */
    private $hasMany2Many;

    public function __construct(string $schemaName, SchemaReader $schema, JunctionSchemas $junctions)
    {
        $this->schemaName = $schemaName;
        $this->schema = $schema;
        $this->tableName = $schema->resolveTableName($schemaName);
        $this->junctions = $junctions;
        $this->isJunctionSchema = $junctions->isJunctionSchema($schemaName);
        $this->hasMany2Many = $junctions->hasMany2Many($schemaName);
    }

    /**
     * @return \cebe\yii2openapi\lib\items\DbModel
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     * @throws \yii\base\InvalidConfigException|\cebe\openapi\exceptions\UnresolvableReferenceException
     */
    public function resolve(): DbModel
    {
        foreach ($this->schema->getProperties() as $property) {
            $isRequired = $this->schema->isRequiredProperty($property->getName());
            if ($this->isJunctionSchema) {
                $this->resolveJunctionTableProperty($property, $isRequired);
            } elseif ($this->hasMany2Many) {
                $this->resolveHasMany2ManyTableProperty($property, $isRequired);
            } else {
                $this->resolveProperty($property, $isRequired);
            }
        }
        return new DbModel([
            'pkName' => $this->schema->getPkName(),
            'name' => $this->schemaName,
            'tableName' => $this->tableName,
            'description' => $this->schema->getDescription(),
            'attributes' => $this->attributes,
            'relations' => $this->relations,
            'many2many' => $this->many2many,
            'indexes' => $this->prepareIndexes($this->schema->getIndexes()),
            //For valid primary keys for junction tables
            'junctionCols' => $this->isJunctionSchema ? $this->junctions->junctionCols($this->schemaName) : []
        ]);
    }

    /**
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     */
    protected function resolveJunctionTableProperty(PropertyReader $property, bool $isRequired)
    {
        if ($this->junctions->isJunctionProperty($this->schemaName, $property->getName())) {
            $junkAttribute = $this->junctions->byJunctionSchema($this->schemaName)[$property->getName()];
            $attribute = new Attribute($property->getName());
            $attribute->setRequired($isRequired)
                ->setDescription($property->getAttr('description', ''))
                ->setReadOnly($property->isReadonly())
                ->setIsPrimary($property->isPrimaryKey())
                ->asReference($junkAttribute['relatedClassName'])
                ->setPhpType($junkAttribute['phpType'])
                ->setDbType($junkAttribute['dbType']);
            $relation = (new AttributeRelation($property->getName(), $junkAttribute['relatedTableName'], $junkAttribute['relatedClassName']))
                ->asHasOne([$junkAttribute['foreignPk'] => $attribute->columnName]);
            $this->relations[$property->getName()] = $relation;
            $this->attributes[$property->getName()] = $attribute->setFakerStub($this->guessFakerStub($attribute, $property));
        } else {
            $this->resolveProperty($property, $isRequired);
        }
    }

    /**
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     */
    protected function resolveHasMany2ManyTableProperty(PropertyReader $property, bool $isRequired):void
    {
        if ($this->junctions->isManyToManyProperty($this->schemaName, $property->getName())) {
            return;
        }
        if ($this->junctions->isJunctionRef($this->schemaName, $property->getName())) {
            $junkAttribute = $this->junctions->indexByJunctionRef()[$property->getName()][$this->schemaName];
            $junkRef = $property->getName();
            $junkProperty = $junkAttribute['property'];
            $viaModel = $this->junctions->trimPrefix($junkAttribute['junctionSchema']);

            $relation = new ManyToManyRelation([
                'name' => Inflector::pluralize($junkProperty),
                'schemaName' => $this->schemaName,
                'relatedSchemaName' => $junkAttribute['relatedClassName'],
                'tableName' => $this->tableName,
                'relatedTableName' => $junkAttribute['relatedTableName'],
                'pkAttribute' => $this->attributes[$this->schema->getPkName()],
                'hasViaModel' => true,
                'viaModelName' => $viaModel,
                'viaRelationName' => Inflector::id2camel($junkRef, '_'),
                'fkProperty' => $junkAttribute['pairProperty'],
                'relatedFkProperty' => $junkAttribute['property'],
            ]);
            $this->many2many[Inflector::pluralize($junkProperty)] = $relation;

            $this->relations[Inflector::pluralize($junkRef)] =
                (new AttributeRelation($junkRef, $junkAttribute['junctionTable'], $viaModel))
                    ->asHasMany([$junkAttribute['pairProperty'] . '_id' => $this->schema->getPkName()]);
            return;
        }

        $this->resolveProperty($property, $isRequired);
    }

    /**
     * @param \cebe\yii2openapi\lib\openapi\PropertyReader $property
     * @param bool                                         $isRequired
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     */
    protected function resolveProperty(PropertyReader $property, bool $isRequired):void
    {
        $attribute = new Attribute($property->getName());
        $attribute->setRequired($isRequired)
            ->setDescription($property->getAttr('description', ''))
            ->setReadOnly($property->isReadonly())
            ->setDefault($property->guessDefault())
            ->setIsPrimary($property->isPrimaryKey());
        if ($property->isReference()) {
            if ($property->isVirtual()) {
                throw new InvalidDefinitionException('References not supported for virtual attributes');
            }

            $fkProperty = $property->getTargetProperty();
            if(!$fkProperty) {
                return;
            }
            $relatedClassName = $property->getRefClassName();
            $relatedTableName =$property->getRefSchema()->resolveTableName($relatedClassName);
            [$min, $max] = $fkProperty->guessMinMax();
            $attribute->asReference($relatedClassName);
            $attribute->setPhpType($fkProperty->guessPhpType())
                      ->setDbType($fkProperty->guessDbType(true))
                      ->setSize($fkProperty->getMaxLength())
                      ->setDescription($property->getRefSchema()->getDescription())
                      ->setDefault($fkProperty->guessDefault())
                      ->setLimits($min, $max, $fkProperty->getMinLength());

            $relation = (new AttributeRelation($property->getName(), $relatedTableName, $relatedClassName))
                ->asHasOne([$fkProperty->getName() => $attribute->columnName]);
            if ($property->isRefPointerToSelf()) {
                $relation->asSelfReference();
            }
            $this->relations[$property->getName()] = $relation;
        }
        if (!$property->isReference() && !$property->hasRefItems()) {
            [$min, $max] = $property->guessMinMax();
            $attribute->setIsVirtual($property->isVirtual())
                      ->setPhpType($property->guessPhpType())
                      ->setDbType($property->guessDbType())
                      ->setSize($property->getMaxLength())
                      ->setLimits($min, $max, $property->getMinLength());
            if ($property->hasEnum()) {
                $attribute->setEnumValues($property->getAttr('enum'));
            }
        }

        if ($property->hasRefItems()) {
            if ($property->isVirtual()) {
                throw new InvalidDefinitionException('References not supported for virtual attributes');
            }
            if ($property->isRefPointerToSelf()) {
                $relatedClassName = $property->getRefClassName();
                $attribute->setPhpType($relatedClassName . '[]');
                $relatedTableName = $this->tableName;
                $fkProperty = $property->getSelfTargetProperty();
                if ($fkProperty && !$fkProperty->isReference() && !StringHelper::endsWith($fkProperty->getName(), '_id')) {
                    $this->relations[$property->getName()] =
                        (new AttributeRelation($property->getName(), $relatedTableName, $relatedClassName))
                            ->asHasMany([$fkProperty->getName() => $fkProperty->getName()])->asSelfReference();
                    return;
                }
                $foreignPk = Inflector::camel2id($fkProperty->getName(), '_') . '_id';
                $this->relations[$property->getName()] =
                    (new AttributeRelation($property->getName(), $relatedTableName, $relatedClassName))
                        ->asHasMany([$foreignPk => $this->schema->getPkName()]);
                return;
            }
            $relatedClassName = $property->getRefClassName();
            $relatedTableName =$property->getRefSchema()->resolveTableName($relatedClassName);
            if ($this->catchManyToMany($property->getName(), $relatedClassName, $relatedTableName, $property->getRefSchema())) {
                return;
            }
            $attribute->setPhpType($relatedClassName . '[]');
            $this->relations[$property->getName()] =
                (new AttributeRelation($property->getName(), $relatedTableName, $relatedClassName))
                    ->asHasMany([Inflector::camel2id($this->schemaName, '_') . '_id' => $this->schema->getPkName()]);
            return;
        }
        $this->attributes[$property->getName()] = $attribute->setFakerStub($this->guessFakerStub($attribute, $property));
    }

    /**
     * Check and register many-to-many relation
     * - property name for many-to-many relation should be equal lower-cased, pluralized schema name
     * - referenced schema should contain mirrored reference to current schema
     * @param string $propertyName
     * @param string $relatedSchemaName
     * @param string $relatedTableName
     * @param SchemaReader $refSchema
     * @return bool
     */
    protected function catchManyToMany(
        string $propertyName,
        string $relatedSchemaName,
        string $relatedTableName,
        SchemaReader $refSchema
    ): bool {
        if (strtolower(Inflector::id2camel($propertyName, '_'))
            !== strtolower(Inflector::pluralize($relatedSchemaName))) {
            return false;
        }
        $expectedPropertyName = strtolower(Inflector::pluralize(Inflector::camel2id($this->schemaName, '_')));
        if (!$refSchema->hasProperty($expectedPropertyName)) {
            return false;
        }
        $refProperty = $refSchema->getProperty($expectedPropertyName);
        if (!$refProperty) {
            return false;
        }
        $refClassName = $refProperty->hasRefItems() ? $refProperty->getRefSchemaName(): null;
        if ($refClassName !== $this->schemaName) {
            return false;
        }
        $relation = new ManyToManyRelation([
            'name' => $propertyName,
            'schemaName' => $this->schemaName,
            'relatedSchemaName' => $relatedSchemaName,
            'tableName' => $this->tableName,
            'relatedTableName' => $relatedTableName,
            'pkAttribute' => $this->attributes[$this->schema->getPkName()],
        ]);
        $this->many2many[$propertyName] = $relation;
        return true;
    }


    protected function guessFakerStub(Attribute $attribute, PropertyReader $property): ?string
    {
        $resolver = Yii::createObject(['class' => FakerStubResolver::class], [$attribute, $property]);
        return $resolver->resolve();
    }

    /**
     * @param array $indexes
     * @return array|DbIndex[]
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     */
    protected function prepareIndexes(array $indexes): array
    {
        $dbIndexes = [];
        foreach ($indexes as $index) {
            $unique = false;
            if (strpos($index, ':') !== false) {
                [$indexType, $props] = explode(':', $index);
            } else {
                $props = $index;
                $indexType = null;
            }
            if ($indexType === 'unique') {
                $indexType = null;
                $unique = true;
            }
            $props = array_map('trim', explode(',', trim($props)));
            $columns = [];
            foreach ($props as $prop) {
                if (!isset($this->attributes[$prop])) {
                    throw new InvalidDefinitionException('Invalid index definition - property ' . $prop . ' not declared');
                }
                $columns[] = $this->attributes[$prop]->columnName;
            }
            $dbIndex = DbIndex::make($this->tableName, $columns, $indexType, $unique);
            $dbIndexes[$dbIndex->name] = $dbIndex;
        }
        return $dbIndexes;
    }
}
