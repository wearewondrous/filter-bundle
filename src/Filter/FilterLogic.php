<?php

namespace Metaclass\FilterBundle\Filter;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Api\FilterCollection;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractContextAwareFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ContextAwareFilterInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Exception\ResourceClassNotFoundException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Doctrine\ORM\Query\Expr\Join;

/**
 * Combines existing API Platform ORM Filters with AND and OR.
 * For usage and limitations see https://gist.github.com/metaclass-nl/790a5c8e9064f031db7d3379cc47c794
 * WARNING: $innerJoinsLeft=true changes the behavior of ExistsFilter =false,
 * and though it makes it more like one would expect given the semantics of its name,
 * it does break backward compatibility.
 *
 * Copyright (c) MetaClass, Groningen, 2021. MIT License
 */
class FilterLogic extends AbstractContextAwareFilter
{
    /** @var ResourceMetadataFactoryInterface  */
    private $resourceMetadataFactory;
    /** @var ContainerInterface|FilterCollection  */
    private $filterLocator;
    /** @var string Filter classes must match this to be applied with logic */
    private $classExp;
    /** @var ContextAwareFilterInterface[] */
    private $filters;
    /** @var bool Wheather to replace all inner joins by left joins */
    private $innerJoinsLeft;

    /**
     * @param ResourceMetadataFactoryInterface $resourceMetadataFactory
     * @param ContainerInterface|FilterCollection $filterLocator
     * @param $regExp string Filter classes must match this to be applied with logic
     * @param $innerJoinsLeft bool Wheather to replace all inner joins by left joins.
     *   This makes the standard Api Platform filters combine properly with OR,
     *   but also changes the behavior of ExistsFilter =false.
     * {@inheritdoc}
     */
    public function __construct(ResourceMetadataFactoryInterface $resourceMetadataFactory, $filterLocator, ManagerRegistry $managerRegistry, LoggerInterface $logger = null, array $properties = null, NameConverterInterface $nameConverter = null, string $classExp='//', $innerJoinsLeft=false)
    {
        parent::__construct($managerRegistry, null, $logger, $properties, $nameConverter);
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->filterLocator = $filterLocator;
        $this->classExp = $classExp;
        $this->innerJoinsLeft = $innerJoinsLeft;
    }

    /** {@inheritdoc } */
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        $reflectionClass = new \ReflectionClass($resourceClass);

        // has entity #[ApiFilter(FilterLogic::class)] ?
        if (false === $this->isFilterLogicFilter($reflectionClass)) {
            return $description;
        }

        // has entity #[ApiFilter(SearchFilter::class)] with props? ?
        $properties = $this->getPropertiesFromSearchFilter($reflectionClass);
        if  (null === $properties) {
            return $description;
        }

        foreach ($properties as $key => $value) {
            $description['and[or]['.$key.']'] = [
                'description' => 'andOr filter',
                'type' => 'string',
                'required' => false,
            ];
            // todo add all filters here and or not ...
        }

        return $description;
    }

    /**
     * {@inheritdoc}
     * @throws ResourceClassNotFoundException
     * @throws \LogicException if assumption proves wrong
     */
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null, array $context = [])
    {
        if (!isset($context['filters']) || !\is_array($context['filters'])) {
            throw new \InvalidArgumentException('::apply without $context[filters] not supported');
        }

        $this->filters = $this->getFilters($resourceClass, $operationName);

        if (isset($context['filters']['and']) ) {
            $expressions = $this->filterProperty('and', $context['filters']['and'], $queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
            foreach($expressions as $exp) {
                $queryBuilder->andWhere($exp);
            };
        }
        if (isset($context['filters']['not']) ) {
            // NOT expressions are combined by parent logic, here defaulted to AND
            $expressions = $this->filterProperty('not', $context['filters']['not'], $queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
            foreach($expressions as $exp) {
                $queryBuilder->andWhere(new Expr\Func('NOT', [$exp]));
            };
        }
        #Issue 10: for security allways AND with existing criteria
        if (isset($context['filters']['or'])) {
            $expressions = $this->filterProperty('or', $context['filters']['or'], $queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
            $queryBuilder->andWhere(new Expr\Orx($expressions));
        }

        if ($this->innerJoinsLeft) {
            $this->replaceInnerJoinsByLeftJoins($queryBuilder);
        }
    }

    /**
     * @return array of Doctrine\ORM\Query\Expr\* and/or string (DQL),
     * each of which must be self-contained in the sense that the intended
     * logic is not compromised if it is combined with the others and other
     * self-contained expressions by
     * Doctrine\ORM\Query\Expr\Andx or Doctrine\ORM\Query\Expr\Orx
     *
     * Adds parameters and joins to $queryBuilder.
     * Caller of this function is responsable for adding the generated
     * expressions to $queryBuilder so that the parameters in the query will
     * correspond 1 to 1 with the parameters that where added by this function.
     * In practice this comes down to adding EACH expression to $queryBuilder
     * once and only once.
     * @throws \LogicException if assumption proves wrong
     */
    public function generateExpressions(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null, array $context = [])
    {
        if (!isset($context['filters']) || !\is_array($context['filters'])) {
            throw new \InvalidArgumentException('::generateExpressions without $context[filters] not supported');
        }
        $this->filters = $this->getFilters($resourceClass, $operationName);
        return $this->doGenerate($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
    }

    /**
     * @throws \LogicException if assumption proves wrong
     */
    protected function doGenerate($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context)
    {
        $oldWhere = $queryBuilder->getDQLPart('where');

        // replace by marker expression
        $marker = new Expr\Func('NOT', []);
        $queryBuilder->add('where', $marker);

        $assoc = [];
        $logic = [];
        foreach ($context['filters'] as $key => $value) {
            if (ctype_digit((string) $key)) {
                // allows the same filter to be applied several times, usually with different arguments
                $subcontext = $context; //copies
                $subcontext['filters'] = $value;
                $this->applyFilters($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $subcontext);

                // apply logic seperately
                if (isset($value['and'])) {
                    $logic[]['and'] =  $value['and'];
                }if (isset($value['or'])) {
                    $logic[]['or'] =  $value['or'];
                }if (isset($value['not'])) {
                    $logic[]['not'] =  $value['not'];
                }
            } elseif (in_array($key, ['and', 'or', 'not'])) {
                $logic[][$key] = $value;
            } else {
                $assoc[$key] = $value;
            }
        }

        // Process $assoc
        $subcontext = $context; //copies
        $subcontext['filters'] = $assoc;
        $this->applyFilters($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $subcontext);

        $newWhere = $queryBuilder->getDQLPart('where');
        $queryBuilder->add('where', $oldWhere); //restores old where

        // force $operator logic upon $newWhere
        $expressions = $this->getAppliedExpressions($newWhere, $marker);

        // Process logic
        foreach ($logic as $eachLogic) {
            $subExpressions = $this->filterProperty(key($eachLogic), current($eachLogic), $queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
            if (key($eachLogic) == 'not') {
                // NOT expressions are combined by parent logic
                foreach ($subExpressions as $subExp) {
                    $expressions[] = new Expr\Func('NOT', [$subExp]);
                }
            } else {
                $expressions[] = key($eachLogic) == 'or'
                    ? new Expr\Orx($subExpressions)
                    : new Expr\Andx($subExpressions);
            }
        }

        return $expressions; // may be empty
    }

    /**
     * @throws \LogicException if assumption proves wrong
     */
    protected function filterProperty(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null, $context=[])
    {
        $subcontext = $context; //copies
        $subcontext['filters'] = $value;
        return $this->doGenerate($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $subcontext);
    }

    /** Calls ::apply on each filter in $filters */
    private function applyFilters($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context)
    {
        foreach ($this->filters as $filter) {
            $filter->apply($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
        }
    }

    /**
     * ASSUMPTION: filters do not use QueryBuilder::where or QueryBuilder::add
     * and create semantically complete expressions in the sense that expressions
     * added to the QueryBundle through ::andWhere or ::orWhere do not depend
     * on one another so that the intended logic is not compromised if they are
     * recombined with the others by either Doctrine\ORM\Query\Expr\Andx
     * or Doctrine\ORM\Query\Expr\Orx.
     *
     * Get expressions from $where
     * andWhere and orWhere allways add their args at the end of existing or
     * new logical expressions, so we started with a marker expression
     * to become the deepest first part. The marker should not be returned
     * @param Expr\Andx | Expr\Orx $where Result from applying filters
     * @param Expr\Func $marker Marks the end of logic resulting from applying filters
     * @return array of ORM Expression
     * @throws \LogicException if assumption proves wrong
     */
    private function getAppliedExpressions($where, $marker)
    {
        if ($where === $marker) {
            return [];
        }
        if (!$where instanceof Expr\Andx && !$where instanceof Expr\Orx) {
            // A filter used QueryBuilder::where or QueryBuilder::add or otherwise
            throw new \LogicException("Assumpion failure, unexpected Expression: ". $where);
        }
        $parts = $where->getParts();
        if (empty($parts)) {
            // A filter used QueryBuilder::where or QueryBuilder::add or otherwise
            throw new \LogicException("Assumpion failure, marker not found");
        }

        $firstPart = array_shift($parts);
        $parts = array_merge($parts, $this->getAppliedExpressions($firstPart, $marker));
        return $parts;
    }


    /**
     * @param string $resourceClass
     * @param string $operationName
     * @return ContextAwareFilterInterface[] From resource except $this and OrderFilters
     * @throws ResourceClassNotFoundException
     */
    protected function getFilters($resourceClass, $operationName)
    {
        $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
        $resourceFilters = $resourceMetadata->getCollectionOperationAttribute($operationName, 'filters', [], true);

        $result = [];
        foreach ($resourceFilters as $filterId) {
            $filter = $this->filterLocator->has($filterId)
                ? $this->filterLocator->get($filterId)
                :  null;
            if ($filter instanceof ContextAwareFilterInterface
                && !($filter instanceof OrderFilter)
                && $filter !== $this
                && preg_match($this->classExp, get_class($filter))
            ) {
                $result[$filterId] = $filter;
            }
        }
        return $result;
    }

    /**
     * The filters that come standard with Api Platform create inner joins,
     * but for nullable and to many references we need Left Joins for OR
     * to also produce results that are not related.
     * WARNING: This changes the behavior of ExistsFilter =false, consider
     * using ExistFilter included in this bundle instead.
     * @param QueryBuilder $queryBuilder
     */
    protected function replaceInnerJoinsByLeftJoins(QueryBuilder $queryBuilder) {
        $joinPart = $queryBuilder->getDQLPart('join');
        $result = [];
        foreach ($joinPart as $rootAlias => $joins) {
            foreach ($joins as $i => $joinExp) {
                if (Join::INNER_JOIN === $joinExp->getJoinType()) {
                    $result[$rootAlias][$i] = new Join(
                        Join::LEFT_JOIN,
                        $joinExp->getJoin(),
                        $joinExp->getAlias(),
                        $joinExp->getConditionType(),
                        $joinExp->getCondition(),
                        $joinExp->getIndexBy()
                      );
                } else {
                    $result[$rootAlias][$i] = $joinExp;
                }
            }
        }
        $queryBuilder->add('join', $result);
    }

    private function getPropertiesFromSearchFilter(\ReflectionClass $class): ?array
    {
        $apiFilterAttributes = $class->getAttributes('ApiPlatform\Core\Annotation\ApiFilter', ReflectionAttribute::IS_INSTANCEOF);

        foreach ($apiFilterAttributes as $filterAttribute) {
            if  ('ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter' === $filterAttribute->newInstance()->filterClass) {
                return $filterAttribute->newInstance()->properties;
            }
        }

        return null;
    }

    private function isFilterLogicFilter(\ReflectionClass $class): bool
    {
        $apiFilterAttributes = $class->getAttributes('ApiPlatform\Core\Annotation\ApiFilter', ReflectionAttribute::IS_INSTANCEOF);

        foreach ($apiFilterAttributes as $filterAttribute) {
            if  ('Metaclass\FilterBundle\Filter\FilterLogic' === $filterAttribute->newInstance()->filterClass) {
                return true;
            }
        }

        return false;
    }
}