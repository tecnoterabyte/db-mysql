<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\sphinx;

use yii\base\Object;
use yii\db\Exception;
use yii\db\Expression;

/**
 * Class QueryBuilder
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
class QueryBuilder extends Object
{
	/**
	 * The prefix for automatically generated query binding parameters.
	 */
	const PARAM_PREFIX = ':sp';

	/**
	 * @var Connection the Sphinx connection.
	 */
	public $db;
	/**
	 * @var string the separator between different fragments of a SQL statement.
	 * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
	 */
	public $separator = " ";

	/**
	 * Constructor.
	 * @param Connection $connection the Sphinx connection.
	 * @param array $config name-value pairs that will be used to initialize the object properties
	 */
	public function __construct($connection, $config = [])
	{
		$this->db = $connection;
		parent::__construct($config);
	}

	/**
	 * Generates a SELECT SQL statement from a [[Query]] object.
	 * @param Query $query the [[Query]] object from which the SQL statement will be generated
	 * @return array the generated SQL statement (the first array element) and the corresponding
	 * parameters to be bound to the SQL statement (the second array element).
	 */
	public function build($query)
	{
		$params = $query->params;
		$clauses = [
			$this->buildSelect($query->select, $query->distinct, $query->selectOption),
			$this->buildFrom($query->from),
			$this->buildWhere($query->where, $params),
			$this->buildGroupBy($query->groupBy),
			$this->buildWithin($query->within),
			$this->buildOrderBy($query->orderBy),
			$this->buildLimit($query->limit, $query->offset),
			$this->buildOption($query->options),
		];
		return [implode($this->separator, array_filter($clauses)), $params];
	}

	/**
	 * Creates an INSERT SQL statement.
	 * For example,
	 *
	 * ~~~
	 * $sql = $queryBuilder->insert('idx_user', [
	 *	 'name' => 'Sam',
	 *	 'age' => 30,
	 * ], $params);
	 * ~~~
	 *
	 * The method will properly escape the index and column names.
	 *
	 * @param string $index the index that new rows will be inserted into.
	 * @param array $columns the column data (name => value) to be inserted into the index.
	 * @param array $params the binding parameters that will be generated by this method.
	 * They should be bound to the DB command later.
	 * @return string the INSERT SQL
	 */
	public function insert($index, $columns, &$params)
	{
		if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
			$columnSchemas = $indexSchema->columns;
		} else {
			$columnSchemas = [];
		}
		$names = [];
		$placeholders = [];
		foreach ($columns as $name => $value) {
			$names[] = $this->db->quoteColumnName($name);
			if ($value instanceof Expression) {
				$placeholders[] = $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				if (is_array($value)) {
					// MVA :
					$placeholderParts = [];
					foreach ($value as $subValue) {
						$phName = self::PARAM_PREFIX . count($params);
						$placeholderParts[] = $phName;
						$params[$phName] = isset($columnSchemas[$name]) ? $columnSchemas[$name]->typecast($subValue) : $subValue;
					}
					$placeholders[] = '(' . implode(',', $placeholderParts) . ')';
				} else {
					$phName = self::PARAM_PREFIX . count($params);
					$placeholders[] = $phName;
					$params[$phName] = isset($columnSchemas[$name]) ? $columnSchemas[$name]->typecast($value) : $value;
				}
			}
		}

		return 'INSERT INTO ' . $this->db->quoteIndexName($index)
			. ' (' . implode(', ', $names) . ') VALUES ('
			. implode(', ', $placeholders) . ')';
	}

	/**
	 * Generates a batch INSERT SQL statement.
	 * For example,
	 *
	 * ~~~
	 * $connection->createCommand()->batchInsert('idx_user', ['name', 'age'], [
	 *     ['Tom', 30],
	 *     ['Jane', 20],
	 *     ['Linda', 25],
	 * ])->execute();
	 * ~~~
	 *
	 * Note that the values in each row must match the corresponding column names.
	 *
	 * @param string $index the index that new rows will be inserted into.
	 * @param array $columns the column names
	 * @param array $rows the rows to be batch inserted into the index
	 * @param array $params the binding parameters that will be generated by this method.
	 * They should be bound to the DB command later.
	 * @return string the batch INSERT SQL statement
	 */
	public function batchInsert($index, $columns, $rows, &$params)
	{
		if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
			$columnSchemas = $indexSchema->columns;
		} else {
			$columnSchemas = [];
		}

		foreach ($columns as $i => $name) {
			$columns[$i] = $this->db->quoteColumnName($name);
		}

		$values = [];
		foreach ($rows as $row) {
			$vs = [];
			foreach ($row as $i => $value) {
				if (is_array($value)) {
					// MVA :
					$vsParts = [];
					foreach ($value as $subValue) {
						$phName = self::PARAM_PREFIX . count($params);
						$vsParts[] = $phName;
						$params[$phName] = isset($columnSchemas[$columns[$i]]) ? $columnSchemas[$columns[$i]]->typecast($subValue) : $subValue;
					}
					$vs[] = '(' . implode(',', $vsParts) . ')';
				} else {
					$phName = self::PARAM_PREFIX . count($params);
					if (isset($columnSchemas[$columns[$i]])) {
						$value = $columnSchemas[$columns[$i]]->typecast($value);
					}
					$params[$phName] = is_string($value) ? $this->db->quoteValue($value) : $value;
					$vs[] = $phName;
				}
			}
			$values[] = '(' . implode(', ', $vs) . ')';
		}

		return 'INSERT INTO ' . $this->db->quoteIndexName($index)
			. ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
	}

	/**
	 * Creates an UPDATE SQL statement.
	 * For example,
	 *
	 * ~~~
	 * $params = [];
	 * $sql = $queryBuilder->update('idx_user', ['status' => 1], 'age > 30', $params);
	 * ~~~
	 *
	 * The method will properly escape the index and column names.
	 *
	 * @param string $index the index to be updated.
	 * @param array $columns the column data (name => value) to be updated.
	 * @param array|string $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the binding parameters that will be modified by this method
	 * so that they can be bound to the DB command later.
	 * @return string the UPDATE SQL
	 */
	public function update($index, $columns, $condition, &$params)
	{
		if (($indexSchema = $this->db->getIndexSchema($index)) !== null) {
			$columnSchemas = $indexSchema->columns;
		} else {
			$columnSchemas = [];
		}

		$lines = [];
		foreach ($columns as $name => $value) {
			if ($value instanceof Expression) {
				$lines[] = $this->db->quoteColumnName($name) . '=' . $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				if (is_array($value)) {
					// MVA :
					$lineParts = [];
					foreach ($value as $subValue) {
						$phName = self::PARAM_PREFIX . count($params);
						$lineParts[] = $phName;
						$params[$phName] = isset($columnSchemas[$name]) ? $columnSchemas[$name]->typecast($subValue) : $subValue;
					}
					$lines[] = $this->db->quoteColumnName($name) . '=' . '(' . implode(',', $lineParts) . ')';
				} else {
					$phName = self::PARAM_PREFIX . count($params);
					$lines[] = $this->db->quoteColumnName($name) . '=' . $phName;
					$params[$phName] = !is_array($value) && isset($columnSchemas[$name]) ? $columnSchemas[$name]->typecast($value) : $value;
				}
			}
		}

		$sql = 'UPDATE ' . $this->db->quoteIndexName($index) . ' SET ' . implode(', ', $lines);
		$where = $this->buildWhere($condition, $params);
		return $where === '' ? $sql : $sql . ' ' . $where;
	}

	/**
	 * Creates a DELETE SQL statement.
	 * For example,
	 *
	 * ~~~
	 * $sql = $queryBuilder->delete('tbl_user', 'status = 0');
	 * ~~~
	 *
	 * The method will properly escape the index and column names.
	 *
	 * @param string $index the index where the data will be deleted from.
	 * @param array|string $condition the condition that will be put in the WHERE part. Please
	 * refer to [[Query::where()]] on how to specify condition.
	 * @param array $params the binding parameters that will be modified by this method
	 * so that they can be bound to the DB command later.
	 * @return string the DELETE SQL
	 */
	public function delete($index, $condition, &$params)
	{
		$sql = 'DELETE FROM ' . $this->db->quoteIndexName($index);
		$where = $this->buildWhere($condition, $params);
		return $where === '' ? $sql : $sql . ' ' . $where;
	}

	/**
	 * Builds a SQL statement for truncating an index.
	 * @param string $index the index to be truncated. The name will be properly quoted by the method.
	 * @return string the SQL statement for truncating an index.
	 */
	public function truncateIndex($index)
	{
		return 'TRUNCATE RTINDEX ' . $this->db->quoteIndexName($index);
	}

	/**
	 * Builds a SQL statement for call snippet from provided data and query, using specified index settings.
	 * @param string $index name of the index, from which to take the text processing settings.
	 * @param string|array $source is the source data to extract a snippet from.
	 * It could be either a single string or array of strings.
	 * @param string $query the full-text query to build snippets for.
	 * @param array $options list of options in format: optionName => optionValue
	 * @param array $params the binding parameters that will be modified by this method
	 * so that they can be bound to the Sphinx command later.
	 * @return string the SQL statement for call snippets.
	 */
	public function callSnippets($index, $source, $query, $options, &$params)
	{
		if (is_array($source)) {
			$dataSqlParts = [];
			foreach ($source as $sourceRow) {
				$phName = self::PARAM_PREFIX . count($params);
				$params[$phName] = $sourceRow;
				$dataSqlParts[] = $phName;
			}
			$dataSql = '(' . implode(',', $dataSqlParts) . ')';
		} else {
			$phName = self::PARAM_PREFIX . count($params);
			$params[$phName] = $source;
			$dataSql = $phName;
		}
		$indexParamName = self::PARAM_PREFIX . count($params);
		$params[$indexParamName] = $index;
		$queryParamName = self::PARAM_PREFIX . count($params);
		$params[$queryParamName] = $query;
		if (!empty($options)) {
			$optionParts = [];
			foreach ($options as $name => $value) {
				$phName = self::PARAM_PREFIX . count($params);
				$params[$phName] = $value;
				$optionParts[] = $phName . ' AS ' . $name;
			}
			$optionSql = ', ' . implode(', ', $optionParts);
		} else {
			$optionSql = '';
		}
		return 'CALL SNIPPETS(' . $dataSql. ', ' . $indexParamName . ', ' . $queryParamName . $optionSql. ')';
	}

	/**
	 * Builds a SQL statement for returning tokenized and normalized forms of the keywords, and,
	 * optionally, keyword statistics.
	 * @param string $index the name of the index from which to take the text processing settings
	 * @param string $text the text to break down to keywords.
	 * @param boolean $fetchStatistic whether to return document and hit occurrence statistics
	 * @param array $params the binding parameters that will be modified by this method
	 * so that they can be bound to the Sphinx command later.
	 * @return string the SQL statement for call keywords.
	 */
	public function callKeywords($index, $text, $fetchStatistic, &$params)
	{
		$indexParamName = self::PARAM_PREFIX . count($params);
		$params[$indexParamName] = $index;
		$textParamName = self::PARAM_PREFIX . count($params);
		$params[$textParamName] = $text;
		return 'CALL KEYWORDS(' . $textParamName . ', ' . $indexParamName . ($fetchStatistic ? ', 1' : '') . ')';
	}

	/**
	 * @param array $columns
	 * @param boolean $distinct
	 * @param string $selectOption
	 * @return string the SELECT clause built from [[query]].
	 */
	public function buildSelect($columns, $distinct = false, $selectOption = null)
	{
		$select = $distinct ? 'SELECT DISTINCT' : 'SELECT';
		if ($selectOption !== null) {
			$select .= ' ' . $selectOption;
		}

		if (empty($columns)) {
			return $select . ' *';
		}

		foreach ($columns as $i => $column) {
			if (is_object($column)) {
				$columns[$i] = (string)$column;
			} elseif (strpos($column, '(') === false) {
				if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $column, $matches)) {
					$columns[$i] = $this->db->quoteColumnName($matches[1]) . ' AS ' . $this->db->quoteColumnName($matches[2]);
				} else {
					$columns[$i] = $this->db->quoteColumnName($column);
				}
			}
		}

		if (is_array($columns)) {
			$columns = implode(', ', $columns);
		}

		return $select . ' ' . $columns;
	}

	/**
	 * @param array $indexes
	 * @return string the FROM clause built from [[query]].
	 */
	public function buildFrom($indexes)
	{
		if (empty($indexes)) {
			return '';
		}

		foreach ($indexes as $i => $index) {
			if (strpos($index, '(') === false) {
				if (preg_match('/^(.*?)(?i:\s+as|)\s+([^ ]+)$/', $index, $matches)) { // with alias
					$indexes[$i] = $this->db->quoteIndexName($matches[1]) . ' ' . $this->db->quoteIndexName($matches[2]);
				} else {
					$indexes[$i] = $this->db->quoteIndexName($index);
				}
			}
		}

		if (is_array($indexes)) {
			$indexes = implode(', ', $indexes);
		}

		return 'FROM ' . $indexes;
	}

	/**
	 * @param string|array $condition
	 * @param array $params the binding parameters to be populated
	 * @return string the WHERE clause built from [[query]].
	 */
	public function buildWhere($condition, &$params)
	{
		$where = $this->buildCondition($condition, $params);
		return $where === '' ? '' : 'WHERE ' . $where;
	}

	/**
	 * @param array $columns
	 * @return string the GROUP BY clause
	 */
	public function buildGroupBy($columns)
	{
		return empty($columns) ? '' : 'GROUP BY ' . $this->buildColumns($columns);
	}

	/**
	 * @param array $columns
	 * @return string the ORDER BY clause built from [[query]].
	 */
	public function buildOrderBy($columns)
	{
		if (empty($columns)) {
			return '';
		}
		$orders = [];
		foreach ($columns as $name => $direction) {
			if (is_object($direction)) {
				$orders[] = (string)$direction;
			} else {
				$orders[] = $this->db->quoteColumnName($name) . ($direction === Query::SORT_DESC ? ' DESC' : '');
			}
		}

		return 'ORDER BY ' . implode(', ', $orders);
	}

	/**
	 * @param integer $limit
	 * @param integer $offset
	 * @return string the LIMIT and OFFSET clauses built from [[query]].
	 */
	public function buildLimit($limit, $offset)
	{
		$sql = '';
		if ($limit !== null && $limit >= 0) {
			$sql = 'LIMIT ' . (int)$limit;
		}
		if ($offset > 0) {
			$sql .= ' OFFSET ' . (int)$offset;
		}
		return ltrim($sql);
	}

	/**
	 * Processes columns and properly quote them if necessary.
	 * It will join all columns into a string with comma as separators.
	 * @param string|array $columns the columns to be processed
	 * @return string the processing result
	 */
	public function buildColumns($columns)
	{
		if (!is_array($columns)) {
			if (strpos($columns, '(') !== false) {
				return $columns;
			} else {
				$columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
			}
		}
		foreach ($columns as $i => $column) {
			if (is_object($column)) {
				$columns[$i] = (string)$column;
			} elseif (strpos($column, '(') === false) {
				$columns[$i] = $this->db->quoteColumnName($column);
			}
		}
		return is_array($columns) ? implode(', ', $columns) : $columns;
	}


	/**
	 * Parses the condition specification and generates the corresponding SQL expression.
	 * @param string|array $condition the condition specification. Please refer to [[Query::where()]]
	 * on how to specify a condition.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 * @throws \yii\db\Exception if the condition is in bad format
	 */
	public function buildCondition($condition, &$params)
	{
		static $builders = [
			'AND' => 'buildAndCondition',
			'OR' => 'buildAndCondition',
			'BETWEEN' => 'buildBetweenCondition',
			'NOT BETWEEN' => 'buildBetweenCondition',
			'IN' => 'buildInCondition',
			'NOT IN' => 'buildInCondition',
			'LIKE' => 'buildLikeCondition',
			'NOT LIKE' => 'buildLikeCondition',
			'OR LIKE' => 'buildLikeCondition',
			'OR NOT LIKE' => 'buildLikeCondition',
		];

		if (!is_array($condition)) {
			return (string)$condition;
		} elseif (empty($condition)) {
			return '';
		}
		if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
			$operator = strtoupper($condition[0]);
			if (isset($builders[$operator])) {
				$method = $builders[$operator];
				array_shift($condition);
				return $this->$method($operator, $condition, $params);
			} else {
				throw new Exception('Found unknown operator in query: ' . $operator);
			}
		} else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
			return $this->buildHashCondition($condition, $params);
		}
	}

	/**
	 * Creates a condition based on column-value pairs.
	 * @param array $condition the condition specification.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 */
	public function buildHashCondition($condition, &$params)
	{
		$parts = [];
		foreach ($condition as $column => $value) {
			if (is_array($value)) { // IN condition
				$parts[] = $this->buildInCondition('IN', [$column, $value], $params);
			} else {
				if (strpos($column, '(') === false) {
					$column = $this->db->quoteColumnName($column);
				}
				if ($value === null) {
					$parts[] = "$column IS NULL";
				} elseif ($value instanceof Expression) {
					$parts[] = "$column=" . $value->expression;
					foreach ($value->params as $n => $v) {
						$params[$n] = $v;
					}
				} else {
					$phName = self::PARAM_PREFIX . count($params);
					$parts[] = "$column=$phName";
					$params[$phName] = $value;
				}
			}
		}
		return count($parts) === 1 ? $parts[0] : '(' . implode(') AND (', $parts) . ')';
	}

	/**
	 * Connects two or more SQL expressions with the `AND` or `OR` operator.
	 * @param string $operator the operator to use for connecting the given operands
	 * @param array $operands the SQL expressions to connect.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 */
	public function buildAndCondition($operator, $operands, &$params)
	{
		$parts = [];
		foreach ($operands as $operand) {
			if (is_array($operand)) {
				$operand = $this->buildCondition($operand, $params);
			}
			if ($operand !== '') {
				$parts[] = $operand;
			}
		}
		if (!empty($parts)) {
			return '(' . implode(") $operator (", $parts) . ')';
		} else {
			return '';
		}
	}

	/**
	 * Creates an SQL expressions with the `BETWEEN` operator.
	 * @param string $operator the operator to use (e.g. `BETWEEN` or `NOT BETWEEN`)
	 * @param array $operands the first operand is the column name. The second and third operands
	 * describe the interval that column value should be in.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 * @throws Exception if wrong number of operands have been given.
	 */
	public function buildBetweenCondition($operator, $operands, &$params)
	{
		if (!isset($operands[0], $operands[1], $operands[2])) {
			throw new Exception("Operator '$operator' requires three operands.");
		}

		list($column, $value1, $value2) = $operands;

		if (strpos($column, '(') === false) {
			$column = $this->db->quoteColumnName($column);
		}
		$phName1 = self::PARAM_PREFIX . count($params);
		$params[$phName1] = $value1;
		$phName2 = self::PARAM_PREFIX . count($params);
		$params[$phName2] = $value2;

		return "$column $operator $phName1 AND $phName2";
	}

	/**
	 * Creates an SQL expressions with the `IN` operator.
	 * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
	 * @param array $operands the first operand is the column name. If it is an array
	 * a composite IN condition will be generated.
	 * The second operand is an array of values that column value should be among.
	 * If it is an empty array the generated expression will be a `false` value if
	 * operator is `IN` and empty if operator is `NOT IN`.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 * @throws Exception if wrong number of operands have been given.
	 */
	public function buildInCondition($operator, $operands, &$params)
	{
		if (!isset($operands[0], $operands[1])) {
			throw new Exception("Operator '$operator' requires two operands.");
		}

		list($column, $values) = $operands;

		$values = (array)$values;

		if (empty($values) || $column === []) {
			return $operator === 'IN' ? '0=1' : '';
		}

		if (count($column) > 1) {
			return $this->buildCompositeInCondition($operator, $column, $values, $params);
		} elseif (is_array($column)) {
			$column = reset($column);
		}
		foreach ($values as $i => $value) {
			if (is_array($value)) {
				$value = isset($value[$column]) ? $value[$column] : null;
			}
			if ($value === null) {
				$values[$i] = 'NULL';
			} elseif ($value instanceof Expression) {
				$values[$i] = $value->expression;
				foreach ($value->params as $n => $v) {
					$params[$n] = $v;
				}
			} else {
				$phName = self::PARAM_PREFIX . count($params);
				$params[$phName] = $value;
				$values[$i] = $phName;
			}
		}
		if (strpos($column, '(') === false) {
			$column = $this->db->quoteColumnName($column);
		}

		if (count($values) > 1) {
			return "$column $operator (" . implode(', ', $values) . ')';
		} else {
			$operator = $operator === 'IN' ? '=' : '<>';
			return "$column$operator{$values[0]}";
		}
	}

	protected function buildCompositeInCondition($operator, $columns, $values, &$params)
	{
		$vss = [];
		foreach ($values as $value) {
			$vs = [];
			foreach ($columns as $column) {
				if (isset($value[$column])) {
					$phName = self::PARAM_PREFIX . count($params);
					$params[$phName] = $value[$column];
					$vs[] = $phName;
				} else {
					$vs[] = 'NULL';
				}
			}
			$vss[] = '(' . implode(', ', $vs) . ')';
		}
		foreach ($columns as $i => $column) {
			if (strpos($column, '(') === false) {
				$columns[$i] = $this->db->quoteColumnName($column);
			}
		}
		return '(' . implode(', ', $columns) . ") $operator (" . implode(', ', $vss) . ')';
	}

	/**
	 * Creates an SQL expressions with the `LIKE` operator.
	 * @param string $operator the operator to use (e.g. `LIKE`, `NOT LIKE`, `OR LIKE` or `OR NOT LIKE`)
	 * @param array $operands the first operand is the column name.
	 * The second operand is a single value or an array of values that column value
	 * should be compared with.
	 * If it is an empty array the generated expression will be a `false` value if
	 * operator is `LIKE` or `OR LIKE` and empty if operator is `NOT LIKE` or `OR NOT LIKE`.
	 * @param array $params the binding parameters to be populated
	 * @return string the generated SQL expression
	 * @throws Exception if wrong number of operands have been given.
	 */
	public function buildLikeCondition($operator, $operands, &$params)
	{
		if (!isset($operands[0], $operands[1])) {
			throw new Exception("Operator '$operator' requires two operands.");
		}

		list($column, $values) = $operands;

		$values = (array)$values;

		if (empty($values)) {
			return $operator === 'LIKE' || $operator === 'OR LIKE' ? '0=1' : '';
		}

		if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
			$andor = ' AND ';
		} else {
			$andor = ' OR ';
			$operator = $operator === 'OR LIKE' ? 'LIKE' : 'NOT LIKE';
		}

		if (strpos($column, '(') === false) {
			$column = $this->db->quoteColumnName($column);
		}

		$parts = [];
		foreach ($values as $value) {
			$phName = self::PARAM_PREFIX . count($params);
			$params[$phName] = $value;
			$parts[] = "$column $operator $phName";
		}

		return implode($andor, $parts);
	}

	/**
	 * @param array $columns
	 * @return string the ORDER BY clause built from [[query]].
	 */
	public function buildWithin($columns)
	{
		if (empty($columns)) {
			return '';
		}
		$orders = [];
		foreach ($columns as $name => $direction) {
			if (is_object($direction)) {
				$orders[] = (string)$direction;
			} else {
				$orders[] = $this->db->quoteColumnName($name) . ($direction === Query::SORT_DESC ? ' DESC' : '');
			}
		}
		return 'WITHIN GROUP ORDER BY ' . implode(', ', $orders);
	}

	/**
	 * @param array $options
	 * @return string the OPTION clause build from [[query]]
	 */
	public function buildOption(array $options)
	{
		if (empty($options)) {
			return '';
		}
		$optionLines = [];
		foreach ($options as $name => $value) {
			$optionLines[] = $name . ' = ' . $value;
		}
		return 'OPTION ' . implode(', ', $optionLines);
	}
}