<?php namespace Franzose\ClosureTable\Extensions;

use \Illuminate\Database\ConnectionInterface;
use \Illuminate\Database\Query\Grammars\Grammar;
use \Illuminate\Database\Query\Processors\Processor;

/**
 * Class QueryBuilder
 * @package Franzose\ClosureTable\Extensions
 */
class QueryBuilder extends \Illuminate\Database\Query\Builder {

    /**
     * @var array
     */
    protected $qattrs;

    /**
     * Create a new query builder instance.
     *
     * @param ConnectionInterface $connection
     * @param Grammar $grammar
     * @param Processor $processor
     * @param array $queriedAttributes
     */
    public function __construct(ConnectionInterface $connection,
                                Grammar $grammar,
                                Processor $processor,
                                array $queriedAttributes)
    {
        parent::__construct($connection, $grammar, $processor);

        $this->qattrs = $queriedAttributes;
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function parent(array $columns = ['*'])
    {
        return $this->select($columns)
            ->join($this->qattrs['closure'], $this->qattrs['ancestor'], '=', $this->qattrs['pk'])
            ->where($this->qattrs['descendant'], '=', $this->qattrs['pkValue'])
            ->where($this->qattrs['depth'], '=', 1);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function ancestors(array $columns = ['*'])
    {
        return $this->select($columns)
            ->join($this->qattrs['closure'], $this->qattrs['ancestor'], '=', $this->qattrs['pk'])
            ->where($this->qattrs['descendant'], '=', $this->qattrs['pkValue'])
            ->where($this->qattrs['depth'], '>', 0);
    }

    /**
     * @param array $columns
     * @param bool $queryChildren
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function descendants(array $columns = ['*'], $queryChildren = false)
    {
        $depthOperator = '>';
        $depthValue = 0;

        if ($queryChildren === true)
        {
            $depthOperator = '=';
            $depthValue = 1;
        }

        return $this->select($columns)
            ->join($this->qattrs['closure'], $this->qattrs['descendant'], '=', $this->qattrs['pk'])
            ->where($this->qattrs['ancestor'], '=', $this->qattrs['pkValue'])
            ->where($this->qattrs['depth'], $depthOperator, $depthValue);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function children(array $columns = ['*'])
    {
        return $this->descendants($columns, true);
    }

    /**
     * @param string $find
     * @param string $direction
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    protected function getSiblingsQuery($find = 'all', $direction = 'both', array $columns = ['*'])
    {
        $query = $this->select($columns)
            ->join($this->qattrs['closure'], $this->qattrs['descendant'], '=', $this->qattrs['pk'])
            ->where($this->qattrs['depth'], '=', $this->qattrs['depthValue']);

        if ($find == 'all' && $direction == 'both')
        {
            $query->where($this->qattrs['descendant'], '<>', $this->qattrs['pkValue']);
        }
        else if ($find == 'one' && $direction == 'both')
        {
            $position = [
                $this->qattrs['positionValue']-1,
                $this->qattrs['positionValue']+1
            ];

            $query->whereIn($this->qattrs['position'], $position);
        }
        else
        {
            switch($direction)
            {
                case 'prev':
                    $operand = '<';
                    $position = $this->qattrs['positionValue']--;
                    break;

                case 'next':
                    $operand = '>';
                    $position = $this->qattrs['positionValue']++;
                    break;
            }

            $operand = ($find == 'all' ? $operand : '=');

            $query->where($this->qattrs['position'], $operand, $position);
        }

        return $query;
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function siblings(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('all', 'both', $columns);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function neighbors(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('one', 'both', $columns);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function prevSiblings(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('all', 'prev', $columns);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function prevSibling(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('one', 'prev', $columns);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function nextSiblings(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('all', 'next', $columns);
    }

    /**
     * @param array $columns
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function nextSibling(array $columns = ['*'])
    {
        return $this->getSiblingsQuery('one', 'next', $columns);
    }

    public function roots(array $columns = ['*'])
    {
        array_push($columns, 'c.'.$this->qattrs['ancestorShort']);

        $whereRaw = '(select count(*) from '.$this->qattrs['closure'].' '.
                    'where '.$this->qattrs['descendantShort'].' = c.'.$this->qattrs['ancestorShort'].' '.
                    'and '.$this->qattrs['depthShort'].' > 0) = 0';

        return $this->select($columns)
            ->distinct()
            ->join($this->qattrs['closure'].' as c', function($join)
                {
                    $join->on('c.'.$this->qattrs['ancestorShort'], '=', $this->qattrs['pk']);
                    $join->on('c.'.$this->qattrs['descendantShort'], '=', $this->qattrs['pk']);
                })
            ->whereRaw($whereRaw);
    }

    public function tree(array $columns = ['*'])
    {
        $ak  = 'c1.'.$this->qattrs['ancestorShort'];
        $dk  = 'c1.'.$this->qattrs['descendantShort'];

        $columns = array_merge($columns, [$ak, $dk, 'c1.'.$this->qattrs['depthShort']]);

        return $this->select($columns)
            ->distinct()
            ->join($this->qattrs['closure'].' as c1', $this->qattrs['pk'], '=', $ak)
            ->join($this->qattrs['closure'].' as c2', $this->qattrs['pk'], '=', 'c2.'.$this->qattrs['descendantShort'])
            ->whereRaw($ak.' = '.$dk);
    }
} 