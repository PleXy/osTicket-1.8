<?php
/*********************************************************************
    class.orm.php

    Simple ORM (Object Relational Mapper) for PHPv4 based on Django's ORM,
    except that complex filter operations are not supported. The ORM simply
    supports ANDed filter operations without any GROUP BY support.

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class VerySimpleModel {
    static $meta = array(
        'table' => false,
        'ordering' => false,
        'pk' => false
    );

    function __construct($row) {
        $this->ht = $row;
        $this->dirty = array();
    }

    function get($field) {
        return $this->ht[$field];
    }
    function __get($field) {
        return $this->ht[$field];
    }

    function set($field, $value) {
        $old = isset($this->ht[$field]) ? $this->ht[$field] : null;
        if ($old != $value) {
            $this->dirty[$field] = $old;
            $this->ht[$field] = $value;
        }
    }
    function __set($field, $value) {
        return $this->set($field, $value);
    }

    function setAll($props) {
        foreach ($props as $field=>$value)
            $this->set($field, $value);
    }

    function _inspect() {
        if (!static::$meta['table'])
            throw new OrmConfigurationError(
                'Model does not define meta.table', $this);
    }

    static function objects() {
        return new QuerySet(get_called_class());
    }

    static function lookup($where) {
        if (!is_array($where))
            // Model::lookup(1), where >1< is the pk value
            $where = array(static::$meta['pk'][0] => $where);
        $list = static::find($where, false, 1);
        return $list[0];
    }

    function delete($pk=false) {
        $table = static::$meta['table'];
        $sql = 'DELETE FROM '.$table;

        if (!$pk) $pk = static::$meta['pk'];
        if (!is_array($pk)) $pk=array($pk);

        foreach ($pk as $p)
            $filter[] = $p.' = '.$this->input($this->get($p));
        $sql .= ' WHERE '.implode(' AND ', $filter).' LIMIT 1';
        return db_affected_rows(db_query($sql)) == 1;
    }

    function save($pk=false, $refetch=false) {
        if (!$pk) $pk = static::$meta['pk'];
        if (!$this->isValid())
            return false;
        if (!is_array($pk)) $pk=array($pk);
        if ($this->__new__)
            $sql = 'INSERT INTO '.$table;
        else
            $sql = 'UPDATE '.$table;
        $filter = $fields = array();
        if (count($this->dirty) === 0)
            return;
        foreach ($this->dirty as $field=>$old)
            if ($this->__new__ or !in_array($field, $pk))
                if (@get_class($model->get($field)) == 'SqlFunction')
                    $fields[] = $field.' = '.$model->get($field)->toSql();
                else
                    $fields[] = $field.' = '.input($model->get($field));
        foreach ($pk as $p)
            $filter[] = $p.' = '.db_input($model->get($p));
        $sql .= ' SET '.implode(', ', $fields);
        if (!$this->__new__) {
            $sql .= ' WHERE '.implode(' AND ', $filter);
            $sql .= ' LIMIT 1';
        }
        if (db_affected_rows(db_query($sql)) != 1)
            return false;
        if ($this->__new__ && count($pk) == 1) {
            $this->ht[$pk[0]] = db_insert_id();
            $this->__new__ = false;
        }
        # Refetch row from database
        # XXX: Too much voodoo
        if ($refetch)
            # XXX: Support composite PK
            $this->ht = static::lookup(
                array($pk[0] => $this->get($pk[0])))->ht;
        return $this->get($pk[0]);
    }

    static function create($ht=false) {
        if (!$ht) $ht=array();
        $class = get_called_class();
        $i = new $class(array());
        $i->__new__ = true;
        foreach ($ht as $field=>$value)
            $i->set($field, $value);
        return $i;
    }

    /**
     * isValid
     *
     * Validates the contents of $this->ht before the model should be
     * committed to the database. This is the validation for the field
     * template -- edited in the admin panel for a form section.
     */
    function isValid() {
        return true;
    }
}

class SqlFunction {
    function SqlFunction($name) {
        $this->func = $name;
        $this->args = array_slice(func_get_args(), 1);
    }

    function toSql() {
        $args = (count($this->args)) ? implode(',', db_input($this->args)) : "";
        return sprintf('%s(%s)', $this->func, $args);
    }
}

class QuerySet {
    var $model;

    var $constraints = array();
    var $exclusions = array();
    var $ordering = array();
    var $limit = false;
    var $offset = 0;
    var $related = array();
    var $values = array();

    var $compiler = 'MySqlCompiler';
    var $iterator = 'ModelInstanceIterator';

    function __construct($model) {
        $this->model = $model;
    }

    function filter() {
        // Multiple arrays passes means OR
        $this->constraints[] = func_get_args();
        return $this;
    }

    function exclude() {
        $this->exclusions[] = func_get_args();
        return $this;
    }

    function order_by() {
        $this->ordering = array_merge($this->ordering, func_get_args());
        return $this;
    }

    function limit($count) {
        $this->limit = $count;
        return $this;
    }

    function offset($at) {
        $this->offset = $at;
        return $this;
    }

    function select_related() {
        $this->related = array_merge($this->related, func_get_args());
        return $this;
    }

    function values() {
        $this->values = func_get_args();
        $this->iterator = 'HashArrayIterator';
        return $this;
    }

    function all($sort=false, $limit=false, $offset=false) {
        self::find(false, $sort, $limit, $offset);
        return $this->getIterator()->asArray();
    }

    function find($where=false, $sort=false, $limit=false, $offset=false) {
        // TODO: Stash parameters and return clone or self
        if ($where)
            $this->filter($where);
        if ($sort)
            $this->order_by($sort);
        if ($limit)
            $this->limit($limit);
        if ($offset)
            $this->offset($offset);
        return $this;
    }

    function getIterator() {
        if (!isset($this->_iterator))
            $this->_iterator = new $this->iterator($this, $this->getQuery());
        return $this->_iterator;
    }

    function __toString() {
        return $this->getQuery();
    }

    function getQuery() {
        if (isset($this->query))
            return $this->query;

        // Load defaults from model
        $model = $this->model;
        if (!$this->ordering && isset($model::$meta['ordering']))
            $this->ordering = $model::$meta['ordering'];

        $compiler = new $this->compiler();
        $this->query = $compiler->compileSelect($this);

        var_dump($compiler->params);
        return $this->query;
    }
}

class ModelInstanceIterator implements Iterator {
    var $model;
    var $resource;
    var $cache = array();
    var $position = 0;
    var $queryset;

    function __construct($queryset, $query) {
        $this->model = $queryset->model;
        $this->resource = db_query($query);
    }

    function buildModel($row) {
        // TODO: Traverse to foreign keys
        return new $this->model($row);
    }

    function fillTo($index) {
        while ($this->resource && $index > count($this->cache)) {
            if ($row = db_fetch_array($this->resource)) {
                $this->cache[] = $this->buildModel($row);
            } else {
                $this->resource = null;
                break;
            }
        }
    }

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->cache;
    }

    // Iterator interface
    function rewind() {
        $this->position = 0;
    }
    function current() {
        $this->fillTo($this->position); 
        return $this->cache[$this->position];
    }
    function key() {
        return $this->position;
    }
    function next() {
        $this->position++;
    }
    function valid() {
        $this->fillTo($this->position);
        return count($this->cache) > $this->position;
    }
}

class MySqlCompiler {
    var $params = array();

    static $operators = array(
        'exact' => '%1$s = %2$s',
        'contains' => ' %1$s LIKE %2$s',
        'gt' => '%1$s > %2$s',
        'lt' => '%1$s < %2$s',
        'isnull' => '%1$s IS NULL',
    );

    function _get_joins_and_field($field, $model, $options=array()) {
        $joins = array();
        $parts = explode('__', $field);
        $field = array_pop($parts);
        if (array_key_exists($field, self::$operators)) {
            $operator = self::$operators[$field];
            $field = array_pop($parts);
        } else {
            $operator = self::$operators['exact'];
        }
        if (count($parts) === 0)
            $parts = array($field);
        foreach ($parts as $p) {
            $constraints = array();
            if (!isset($model::$meta['joins'][$p]))
                break;
            $info = $model::$meta['joins'][$p];
            $join = ' JOIN ';
            if (isset($info['null']) && $info['null'])
                $join = ' LEFT'.$join;
            foreach ($info['constraint'] as $local => $foreign) {
                $table = $model::$meta['table'];
                list($model, $right) = explode('.', $foreign);
                $constraints[] = sprintf("%s.%s = %s.%s",
                    $this->quote($table), $this->quote($local),
                    $this->quote($model::$meta['table']), $this->quote($right)
                );
            }
            $joins[] = $join.$this->quote($model::$meta['table'])
                .' ON ('.implode(' AND ', $constraints).')';
        }
        // TODO: Use table aliases
        if (isset($options['table']) && $options['table'])
            $field = $this->quote($model::$meta['table']);
        elseif ($table)
            $field = $this->quote($table).'.'.$this->quote($field);
        else
            $field = $this->quote($field);
        return array($joins, $field, $operator);
    }

    function _compile_where($where, $model) {
        $joins = array();
        $constrints = array();
        foreach ($where as $constraint) {
            $filter = array();
            foreach ($constraint as $field=>$value) {
                list($js, $field, $op) = self::_get_joins_and_field($field, $model);
                $joins = array_merge($joins, $js);
                $filter[] = sprintf($op, $field, $this->input($value));
            }
            // Multiple constraints here are ANDed together
            $constraints[] = implode(' AND ', $filter);
        }
        // Multiple constrains here are ORed together
        $filter = implode(' OR ', $constraints);
        if (count($constraints) > 1)
            $filter = '(' . $filter . ')';
        return array($joins, $filter);
    }

    function input($what) {
        $this->params[] = $what;
        return '?';
    }

    function quote($what) {
        return "`$what`";
    }

    function getParams() {
        return $this->params;
    }

    function compileCount($queryset) {
        $model = $queryset->model;
        $table = $model::$meta['table'];
        if ($where) {
            list($joins, $filter) = static::_compile_where($where);
            $where = ' WHERE ' . implode(' AND ', $filter);
            $joins = implode('', array_unique($joins));
        }
        $sql = 'SELECT COUNT(*) FROM '.$this->quote($table).$joins.$where;
        return db_count($sql);
    }

    function compileSelect($queryset) {
        $model = $queryset->model;
        $where_pos = array();
        $where_neg = array();
        $joins = array();
        foreach ($queryset->constraints as $where) {
            list($_joins, $filter) = $this->_compile_where($where, $model);
            $where_pos[] = $filter;
            $joins = array_merge($joins, $_joins);
        }
        foreach ($queryset->exclusions as $where) {
            list($_joins, $filter) = $this->_compile_where($where, $model);
            $where_neg[] = $filter;
            $joins = array_merge($joins, $_joins);
        }

        $where = '';
        if ($where_pos || $where_neg) {
            $where = ' WHERE '.implode(' AND ', $where_pos)
                .implode(' AND NOT ', $where_neg);
        }

        $sort = '';
        if ($queryset->ordering) {
            $orders = array();
            foreach ($queryset->ordering as $sort) {
                $dir = 'ASC';
                if (substr($sort, 0, 1) == '-') {
                    $dir = 'DESC';
                    $sort = substr($sort, 1);
                }
                list($js, $field) = $this->_get_joins_and_field($sort, $model);
                $joins = ($joins) ? array_merge($joins, $js) : $js;
                $orders[] = $field.' '.$dir;
            }
            $sort = ' ORDER BY '.implode(', ', $orders);
        }

        // Include related tables
        $fields = array();
        $table = $model::$meta['table'];
        if ($queryset->related) {
            $tables = array($this->quote($table));
            foreach ($queryset->related as $rel) {
                list($js, $t) = $this->_get_joins_and_field($rel, $model,
                    array('table'=>true));
                $fields[] = $t.'.*';
                $joins = array_merge($joins, $js);
            }
        } elseif ($queryset->values) {
            foreach ($queryset->values as $v) {
                list($js, $fields[]) = $this->_get_joins_and_field($v, $model);
                $joins = array_merge($joins, $js);
            }
        } else {
            $fields[] = $this->quote($table).'.*';
        }

        if (is_array($joins))
            # XXX: This will change the order of the joins
            $joins = implode('', array_unique($joins));
        $sql = 'SELECT '.implode(', ', $fields).' FROM '
            .$this->quote($table).$joins.$where.$sort;
        if ($queryset->limit)
            $sql .= ' LIMIT '.$limit;
        if ($queryset->offset)
            $sql .= ' OFFSET '.$offset;

        return $sql;
    }

    function compileUpdate() {
    }

    function compileInsert() {
    }
    
    function compileDelete() {
    }

    // Returns meta data about the table used to build queries
    function inspectTable($table) {
    }
}
?>
