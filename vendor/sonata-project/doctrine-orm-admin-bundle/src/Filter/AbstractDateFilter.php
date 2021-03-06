<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Filter;

use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Form\Type\Filter\DateRangeType;
use Sonata\AdminBundle\Form\Type\Filter\DateTimeRangeType;
use Sonata\AdminBundle\Form\Type\Filter\DateTimeType;
use Sonata\AdminBundle\Form\Type\Filter\DateType;

abstract class AbstractDateFilter extends Filter
{
    public const CHOICES = [
        DateType::TYPE_EQUAL => '=',
        DateType::TYPE_GREATER_EQUAL => '>=',
        DateType::TYPE_GREATER_THAN => '>',
        DateType::TYPE_LESS_EQUAL => '<=',
        DateType::TYPE_LESS_THAN => '<',
        DateType::TYPE_NULL => 'NULL',
        DateType::TYPE_NOT_NULL => 'NOT NULL',
    ];

    /**
     * Flag indicating that filter will have range.
     *
     * @var bool
     */
    protected $range = false;

    /**
     * Flag indicating that filter will filter by datetime instead by date.
     *
     * @var bool
     */
    protected $time = false;

    public function filter(ProxyQueryInterface $queryBuilder, $alias, $field, $data)
    {
        // check data sanity
        if (!$data || !\is_array($data) || !\array_key_exists('value', $data)) {
            return;
        }

        if ($this->range) {
            // additional data check for ranged items
            if (!\array_key_exists('start', $data['value']) || !\array_key_exists('end', $data['value'])) {
                return;
            }

            if (!$data['value']['start'] && !$data['value']['end']) {
                return;
            }

            // date filter should filter records for the whole days
            if (false === $this->time) {
                if ($data['value']['start'] instanceof \DateTime) {
                    $data['value']['start']->setTime(0, 0, 0);
                }
                if ($data['value']['end'] instanceof \DateTime) {
                    $data['value']['end']->setTime(23, 59, 59);
                }
            }

            // transform types
            if ('timestamp' === $this->getOption('input_type')) {
                $data['value']['start'] = $data['value']['start'] instanceof \DateTime ? $data['value']['start']->getTimestamp() : 0;
                $data['value']['end'] = $data['value']['end'] instanceof \DateTime ? $data['value']['end']->getTimestamp() : 0;
            }

            // default type for range filter
            $data['type'] = !isset($data['type']) || !is_numeric($data['type']) ? DateRangeType::TYPE_BETWEEN : $data['type'];

            $startDateParameterName = $this->getNewParameterName($queryBuilder);
            $endDateParameterName = $this->getNewParameterName($queryBuilder);

            if (DateRangeType::TYPE_NOT_BETWEEN === $data['type']) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s < :%s OR %s.%s > :%s', $alias, $field, $startDateParameterName, $alias, $field, $endDateParameterName));
            } else {
                if ($data['value']['start']) {
                    $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '>=', $startDateParameterName));
                }

                if ($data['value']['end']) {
                    $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '<=', $endDateParameterName));
                }
            }

            if ($data['value']['start']) {
                $queryBuilder->setParameter($startDateParameterName, $data['value']['start']);
            }

            if ($data['value']['end']) {
                $queryBuilder->setParameter($endDateParameterName, $data['value']['end']);
            }
        } else {
            if (!$data['value']) {
                return;
            }

            // default type for simple filter
            $data['type'] = !isset($data['type']) || !is_numeric($data['type']) ? DateType::TYPE_EQUAL : $data['type'];

            // just find an operator and apply query
            $operator = $this->getOperator($data['type']);

            // transform types
            if ('timestamp' === $this->getOption('input_type')) {
                $data['value'] = $data['value'] instanceof \DateTime ? $data['value']->getTimestamp() : 0;
            }

            // null / not null only check for col
            if (\in_array($operator, ['NULL', 'NOT NULL'], true)) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s IS %s ', $alias, $field, $operator));

                return;
            }

            $parameterName = $this->getNewParameterName($queryBuilder);

            // date filter should filter records for the whole day
            if (false === $this->time && DateType::TYPE_EQUAL === $data['type']) {
                $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '>=', $parameterName));
                $queryBuilder->setParameter($parameterName, $data['value']);

                $endDateParameterName = $this->getNewParameterName($queryBuilder);
                $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, '<', $endDateParameterName));
                if ('timestamp' === $this->getOption('input_type')) {
                    $endValue = strtotime('+1 day', $data['value']);
                } else {
                    $endValue = clone $data['value'];
                    $endValue->add(new \DateInterval('P1D'));
                }
                $queryBuilder->setParameter($endDateParameterName, $endValue);

                return;
            }

            $this->applyWhere($queryBuilder, sprintf('%s.%s %s :%s', $alias, $field, $operator, $parameterName));
            $queryBuilder->setParameter($parameterName, $data['value']);
        }
    }

    public function getDefaultOptions()
    {
        return [
            'input_type' => 'datetime',
        ];
    }

    public function getRenderSettings()
    {
        $name = DateType::class;

        if ($this->time && $this->range) {
            $name = DateTimeRangeType::class;
        } elseif ($this->time) {
            $name = DateTimeType::class;
        } elseif ($this->range) {
            $name = DateRangeType::class;
        }

        return [$name, [
            'field_type' => $this->getFieldType(),
            'field_options' => $this->getFieldOptions(),
            'label' => $this->getLabel(),
        ]];
    }

    /**
     * Resolves DataType:: constants to SQL operators.
     *
     * @param int $type
     *
     * @return string
     */
    protected function getOperator($type)
    {
        $type = (int) $type;

        return self::CHOICES[$type] ?? '=';
    }
}
