<?php

// 关系变体后缀：每个 has_one/belongs_to/has_many 自动注册带此后缀的变体，查询时包含软删除记录
define('ENTITY_RELATIONSHIP_DELETED_SUFFIX', '_with_deleted');
define('ENTITY_DEFAULT_ERROR_CODE', 'ENTITY_DEFAULT_ERROR');

// Active Record 实体基类 — 内置 id/version/create_time/update_time/delete_time 五个系统字段，
// 通过 structs（数据库快照）与 attributes（内存当前值）对比实现脏检测。
// 配合 Unit of Work 自动持久化：just_new → INSERT，just_updated → UPDATE + 乐观锁，just_deleted → 软删除。
abstract class entity implements JsonSerializable, Serializable
{
    const INIT_VERSION = 0;

    // 五个系统字段由框架管理，子类在 $structs 中只需声明业务字段
    public $id;
    public $version;
    public $create_time;
    public $update_time;
    public $delete_time;

    // 仅当前请求内有效，不会持久化到数据库
    private $just_deleted;
    private $just_force_deleted;

    // structs = 数据库中原始值，attributes = 内存值，不一致表示有未提交变更
    public $structs = [];
    public $attributes = [];

    // 定义 null_entity 访问属性时的默认返回值，子类静态声明，如 ['status' => 1]
    public static $null_entity_mock_attributes = [];

    // relationships = 已加载的关系数据缓存，relationship_refs = 关系元数据（按需懒加载）
    private $relationships = [];
    private $relationship_refs = [];

    // 子类 create() 调用此入口，version=0 使 just_new() 返回 true，Unit of Work 据此生成 INSERT
    protected static function init()
    {
        $static = new static();
        $static->attributes = $static->structs;
        $static->id = self::generate_id();
        $static->version = self::INIT_VERSION;
        $static->create_time = $static->update_time = datetime();
        $static->delete_time = null;

        $static->just_deleted = false;
        $static->just_force_deleted = false;

        local_cache_set($static);

        return $static;
    }

    final public static function generate_id()
    {
        return generate_id(get_called_class());
    }

    final public function just_new()
    {
        return self::INIT_VERSION === $this->version;
    }

    final public function just_updated()
    {
        return $this->attributes != $this->structs;
    }

    final public function is_deleted()
    {
        return ! is_null($this->delete_time);
    }

    final public function is_not_deleted()
    {
        return is_null($this->delete_time);
    }

    final public function just_deleted()
    {
        return $this->just_deleted;
    }

    // 标记软删除，Unit of Work 提交时生成 UPDATE SET delete_time
    public function delete()
    {
        $this->just_deleted = true;
        $this->delete_time = datetime();
    }

    final public function restore()
    {
        $this->just_deleted = false;
        $this->delete_time = null;
    }

    final public function just_force_deleted()
    {
        return $this->just_force_deleted;
    }

    // 标记硬删除，Unit of Work 提交时生成 DELETE FROM
    final public function force_delete()
    {
        $this->just_force_deleted = true;
    }

    // null_entity 重写返回 true，正常 entity 永远返回 false
    public function is_null()
    {
        return false;
    }

    public function is_not_null()
    {
        return ! $this->is_null();
    }

    final public function get_dao()
    {
        return dao(get_class($this));
    }

    public function jsonSerialize(): array
    {
        return array_merge([
            'id' => $this->id,
            'version' => $this->version,
            'create_time' => $this->create_time,
            'update_time' => $this->update_time,
            'delete_time' => $this->delete_time,
        ], $this->attributes);
    }

    // 排除 relationships 避免循环引用
    public function serialize()
    {
        $serializable = get_object_vars($this);

        unset($serializable['relationships']);

        return serialize($serializable);
    }

    public function unserialize($serialized)
    {
        $unserialized = unserialize($serialized);

        foreach($unserialized as $property => $value) {

            $this->{$property} = $value;

        }
    }

    public function __serialize(): array
    {
        $serializable = get_object_vars($this);

        unset($serializable['relationships']);

        return $serializable;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {

            $this->{$property} = $value;

        }
    }

    // 访问器优先级链：
    // 1. get_{property}() 方法（子类自定义访问器）
    // 2. 已加载的关系数据（relationships 缓存，含本次请求刚赋值的）
    // 3. 懒加载关系（首次访问时通过 relationship_ref 查询并缓存到 relationships）
    // 4. attributes 中的原始值
    public function __get($property)
    {
        $method = "get_$property";

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        if (isset($this->relationships[$property])) {
            return $this->relationships[$property];
        }

        if (isset($this->relationship_refs[$property])) {
            return $this->load_relationship_from_ref($property);
        }

        return $this->attributes[$property];
    }

    // 赋值分流：
    // 1. 如果是关系属性 → 调用 relationship_ref->update() 维护外键，再将值缓存到 relationships
    // 2. 如果是业务字段 → 直接写入 attributes（structs 保持不变，标记为脏）
    // 已加载过关系时传入 old_entity，让 update() 可以解除旧关联
    final public function __set($property, $value)
    {
        if (isset($this->relationship_refs[$property])) {

            if (isset($this->relationships[$property])) {

                $this->relationship_refs[$property]->update($value, $this, $this->relationships[$property]);
            } else {
                $this->relationship_refs[$property]->update($value, $this);
            }

            return $this->relationships[$property] = $value;
        }

        if (array_key_exists($property, $this->attributes)) {

            return $this->attributes[$property] = $value;
        }
    }

    // unset 仅允许用于已加载的关系缓存，不支持删除 attributes 中的字段
    final public function __unset($property)
    {
        otherwise(
            isset($this->relationships[$property]),
            '只能 unset 实体的关联关系',
            'exception',
            ENTITY_DEFAULT_ERROR_CODE
        );

        unset($this->relationships[$property]);
    }

    final public function __isset($property)
    {
        $method = "get_$property";

        return isset($this->attributes[$property])
            || isset($this->relationships[$property])
            || method_exists($this, $method);
    }

    // 懒加载入口：首次访问关系属性时，通过 relationship_ref 查询并缓存到 relationships
    private function load_relationship_from_ref($relationship_name)
    {
        $relationship_ref = $this->relationship_refs[$relationship_name];

        return $this->relationships[$relationship_name] = $relationship_ref->load($this);
    }

    // 定义 has_one 关系：子实体通过 foreign_key 指向本实体，同时自动注册 _with_deleted 变体
    protected function has_one($relationship_name, ?string $entity_name = null, ?string $foreign_key = null)
    {
        $self_entity_name = get_class($this);

        if (is_null($entity_name)) {
            $entity_name = $relationship_name;
        }

        if (is_null($foreign_key)) {
            $foreign_key = $self_entity_name.'_id';
        }

        $this->relationship_refs[$relationship_name] = instance('has_one', [$entity_name, $foreign_key]);
        $this->relationship_refs[$relationship_name.ENTITY_RELATIONSHIP_DELETED_SUFFIX] = instance('has_one', [$entity_name, $foreign_key, true]);
    }

    // 定义 belongs_to 关系：foreign_key 默认按实体名推导（如 user → user_id），同时自动注册 _with_deleted 变体
    protected function belongs_to($relationship_name, ?string $entity_name = null, ?string $foreign_key = null)
    {
        if (is_null($entity_name)) {
            $entity_name = $relationship_name;
        }

        if (is_null($foreign_key)) {
            $foreign_key = $entity_name.'_id';
        }

        $this->relationship_refs[$relationship_name] = instance('belongs_to', [$entity_name, $foreign_key]);
        $this->relationship_refs[$relationship_name.ENTITY_RELATIONSHIP_DELETED_SUFFIX] = instance('belongs_to', [$entity_name, $foreign_key, true]);
    }

    // 定义 has_many 关系：foreign_key 默认按当前实体名推导，同时自动注册 _with_deleted 变体
    protected function has_many($relationship_name, ?string $entity_name = null, ?string $foreign_key = null)
    {
        $self_entity_name = get_class($this);

        if (is_null($entity_name)) {
            $entity_name = $relationship_name;
        }

        if (is_null($foreign_key)) {
            $foreign_key = $self_entity_name.'_id';
        }

        $this->relationship_refs[$relationship_name] = instance('has_many', [$entity_name, $foreign_key]);
        $this->relationship_refs[$relationship_name.ENTITY_RELATIONSHIP_DELETED_SUFFIX] = instance('has_many', [$entity_name, $foreign_key, true]);
    }

    // 批量加载关系防止 N+1
    public function relationship_batch_load($relationship_name, array $from_entities)
    {
        otherwise(
            array_key_exists($relationship_name, $this->relationship_refs),
            'entity ['.get_class($this).'] has not a relationship called ['.$relationship_name.']',
            'exception',
            ENTITY_DEFAULT_ERROR_CODE
        );

        $relationship_ref = $this->relationship_refs[$relationship_name];

        return $relationship_ref->batch_load($from_entities, $relationship_name);
    }

}

// 空对象模式：dao 查询无结果时返回此对象，链式访问不报错，避免 NPE。
// $null_entity_mock_attributes 允许子类声明属性默认值，如 null_entity('user')->status → 1
class null_entity extends entity
{
    public $id = 0;

    private $mock_entity_name = null;

    public static function create(?string $mock_entity_name = null)
    {
        $null_entity = new static;
        $null_entity->mock_entity_name = $mock_entity_name;

        return $null_entity;
    }

    public function is_null()
    {
        return true;
    }

    // 调用任意方法静默忽略
    public function __call($method, $args)
    {
        return;
    }

    // 链式 null 传播：$post->creator->name，creator 为 null_entity 时继续返回 null_entity
    public function __get($property)
    {
        $mock_entity_name = $this->mock_entity_name;

        if (! is_null($mock_entity_name)) {

            $null_entity_mock_attribute_list = $mock_entity_name::$null_entity_mock_attributes;

            if (array_key_exists($property, $null_entity_mock_attribute_list)) {

                return $null_entity_mock_attribute_list[$property];
            }
        }

        return self::create($property);
    }

    public function __toString()
    {
        return '空';
    }
}

abstract class relationship_ref
{
    abstract public function load(entity $from_entity);
    abstract public function batch_load(array $from_entity, $relationship_name);
    // $values: 新赋值的实体/实体数组；$old_value: 旧的关联实体，用于解除旧外键绑定
    abstract public function update($values, entity $from_entity, $old_value);
}

// has_one：子实体表含 foreign_key，通过 foreign_key = from_entity.id 查询唯一子实体
class has_one extends relationship_ref
{
    private $entity_name;
    private $foreign_key;
    private $with_deleted;

    public function __construct($entity_name, $foreign_key, $with_deleted = false)
    {
        $this->entity_name = $entity_name;
        $this->foreign_key = $foreign_key;
        $this->with_deleted = $with_deleted;
    }

    public function load(entity $from_entity)
    {
        return dao($this->entity_name, $this->with_deleted)->find_by_column([
            $this->foreign_key => $from_entity->id,
        ]);
    }

    // batch_load：$from_entities 的 key 是实体 id，一次查询后按 foreign_key 回填
    public function batch_load(array $from_entities, $relationship_name)
    {
        $ids = array_keys($from_entities);

        $entities = dao($this->entity_name, $this->with_deleted)->find_all_by_column([
            $this->foreign_key => $ids,
        ]);

        foreach ($entities as $entity) {

            $from_entity = $from_entities[$entity->{$this->foreign_key}];

            $from_entity->{$relationship_name} = $entity;
        }

        return $entities;
    }

    public function update($entity, entity $from_entity, $old_entity = null)
    {
        if ($old_entity instanceof entity) {
            $old_entity->{$this->foreign_key} = 0;
        }

        if ($entity instanceof entity) {
            $entity->{$this->foreign_key} = $from_entity->id;
        }
    }
}

// belongs_to：本实体表含 foreign_key，通过 foreign_key 值查询父实体
class belongs_to extends relationship_ref
{
    private $entity_name;
    private $foreign_key;
    private $with_deleted;

    public function __construct($entity_name, $foreign_key, $with_deleted = false)
    {
        $this->entity_name = $entity_name;
        $this->foreign_key = $foreign_key;
        $this->with_deleted = $with_deleted;
    }

    public function load(entity $from_entity)
    {
        return dao($this->entity_name, $this->with_deleted)->find_by_id($from_entity->{$this->foreign_key});
    }

    // 跳过 null_entity，因其无合法 foreign_key
    public function batch_load(array $from_entities, $relationship_name)
    {
        $ids_keys = [];

        foreach ($from_entities as $from_entity) {

            if ($from_entity instanceof entity && $from_entity->is_not_null()) {

                $ids_keys[$from_entity->{$this->foreign_key}] = null;
            }
        }

        $ids = array_keys($ids_keys);

        $entities = dao($this->entity_name, $this->with_deleted)->find_all_by_ids($ids);

        foreach ($from_entities as $from_entity) {

            if (array_key_exists($from_entity->{$this->foreign_key}, $entities)) {

                $from_entity->{$relationship_name} = $entities[$from_entity->{$this->foreign_key}];
            }
        }

        return $entities;
    }

    public function update($entity, entity $from_entity, $old_entity = null)
    {
        if ($entity instanceof entity) {
            $from_entity->{$this->foreign_key} = $entity->id;
        } else {
            $from_entity->{$this->foreign_key} = 0;
        }
    }
}

// has_many：子实体表含 foreign_key，通过 foreign_key = from_entity.id 查询多条子实体
class has_many extends relationship_ref
{
    private $entity_name;
    private $foreign_key;
    private $with_deleted;

    public function __construct($entity_name, $foreign_key, $with_deleted = false)
    {
        $this->entity_name = $entity_name;
        $this->foreign_key = $foreign_key;
        $this->with_deleted = $with_deleted;
    }

    public function load(entity $from_entity)
    {
        return dao($this->entity_name, $this->with_deleted)->find_all_by_column([
            $this->foreign_key => $from_entity->id,
        ]);
    }

    // 一次查询出所有子实体，按 foreign_key 分桶后回填
    public function batch_load(array $from_entities, $relationship_name)
    {
        $ids = array_keys($from_entities);

        $entities = dao($this->entity_name, $this->with_deleted)->find_all_by_column([
            $this->foreign_key => $ids,
        ]);

        $entities_indexed_by_foreign_key = [];

        foreach ($entities as $entity) {

            $foreign_id = $entity->{$this->foreign_key};

            if (! array_key_exists($foreign_id,  $entities_indexed_by_foreign_key)) {

                $entities_indexed_by_foreign_key[$foreign_id] = [];
            }

            $entities_indexed_by_foreign_key[$foreign_id][$entity->id] = $entity;
        }

        foreach ($from_entities as $from_entity) {

            $from_entity->{$relationship_name} = $entities_indexed_by_foreign_key[$from_entity->id] ?? [];
        }

        return $entities;
    }

    public function update($entities, entity $from_entity, $old_entities = [])
    {
        foreach ($old_entities as $old_entity) {
            $old_entity->{$this->foreign_key} = $from_entity->id;
        }

        foreach ($entities as $entity) {
            $entity->{$this->foreign_key} = $from_entity->id;
        }
    }
}

abstract class dao
{
    // class_name 由构造函数从类名自动推导（去掉 _dao 后缀）
    protected $class_name;
    protected $table_name;
    protected $db_config_key;
    // 通过 dao($name, true) 设置，控制查询是否包含软删除记录
    protected $with_deleted;

    public function __construct()
    {
        $this->class_name = substr(get_class($this), 0, -4);
    }

    public function set_with_deleted($with_deleted)
    {
        $this->with_deleted = $with_deleted;
    }

    // 三个方法是软删除过滤的规范入口，仅区别前缀连接词：and / where / where...and。
    // DAO 子类新增 find_by_xxx / find_all_by_xxx 拼接 SQL 时统一调用这三个方法注入软删除条件，禁止手写 delete_time is null

    final protected function with_deleted_and_sql(?string $alias = null)
    {
        $alias = $alias ? $alias.'.': '';

        if ($this->with_deleted) {
            return '';
        } else {
            return ' and '.$alias.'delete_time is null';
        }
    }

    final protected function with_deleted_where_sql(?string $alias = null)
    {
        $alias = $alias ? $alias.'.': '';

        if ($this->with_deleted) {
            return '';
        } else {
            return ' where '.$alias.'delete_time is null';
        }
    }

    final protected function with_deleted_where_sql_and(?string $alias = null)
    {
        $alias = $alias ? $alias.'.': '';

        if ($this->with_deleted) {
            return ' where';
        } else {
            return ' where '.$alias.'delete_time is null and';
        }
    }

    // 本地缓存作为 read-through 缓存：命中直接返回，未命中查库并写入缓存，不存在返回 null_entity 而非 null
    public function find_by_id($id)
    {
        if (empty($id)) {
            return null_entity::create($this->class_name);
        }

        $entity = local_cache_get($this->class_name, $id);

        if (is_null($entity)) {

            $with_deleted_sql = $this->with_deleted_and_sql();

            $row = db_query_first('select * from `'.$this->table_name.'` where id = :id '.$with_deleted_sql, [':id' => $id], $this->db_config_key);
            if ($row) {
                $entity = $this->row_to_entity($row);
                local_cache_set($entity);
            } else {
                $entity = null_entity::create($this->class_name);
            }
        }

        return $entity;
    }

    public function find_by_column(array $columns)
    {
        if (! $this->with_deleted) {
            $columns['delete_time'] = null;
        } else {
            unset($columns['delete_time']);
        }

        list($where, $binds) = db_simple_where_sql($columns);

        return $this->find_by_sql('select * from `'.$this->table_name."` where $where order by id", $binds);
    }

    protected function find_by_condition($condition, array $binds = [])
    {
        $with_deleted_sql = $this->with_deleted_where_sql_and();

        return $this->find_by_sql('select * from `'.$this->table_name.'`'.$with_deleted_sql.' '.$condition, $binds);
    }

    // 单条查询内部基方法：查库并回写本地缓存，不存在返回 null_entity
    protected function find_by_sql($sql_template, array $binds = [])
    {
        $row = db_query_first($sql_template, $binds, $this->db_config_key);

        if (empty($row)) {
            return null_entity::create($this->class_name);
        }

        $entity = local_cache_get($this->class_name, $row['id']);
        if (!is_null($entity)) {
            return $entity;
        }

        $entity = $this->row_to_entity($row);
        local_cache_set($entity);

        return $entity;
    }

    // 按 ID 列表批量查询，用 find_in_set 排序确保返回数组顺序与 $ids 输入顺序一致
    public function find_all_by_ids(array $ids)
    {
        if (empty($ids)) {
            return [];
        }

        $with_deleted_sql = $this->with_deleted_and_sql();

        $sql = [
            'sql_template' => 'select * from `'.$this->table_name.'` where id in :ids'.$with_deleted_sql.' order by find_in_set(id, :set)',
            'binds' => [
                ':ids' => $ids,
                ':set' => implode(',', $ids),
            ],
        ];

        $rows = db_query($sql['sql_template'], $sql['binds'], $this->db_config_key);

        $entities = [];

        foreach ($rows as $row) {
            $entity = local_cache_get($this->class_name, $row['id']);
            if (is_null($entity)) {
                $entity = $this->row_to_entity($row);
                local_cache_set($entity);
            }
            $entities[$entity->id] = $entity;
        }

        return $entities;
    }

    public function find_all()
    {
        $with_deleted_sql = $this->with_deleted_where_sql();

        return $this->find_all_by_sql('select * from `'.$this->table_name.'`'.$with_deleted_sql.' order by id', []);
    }

    public function find_all_order_by_id_desc()
    {
        $with_deleted_sql = $this->with_deleted_where_sql();

        return $this->find_all_by_sql('select * from `'.$this->table_name.'`'.$with_deleted_sql.' order by id desc', []);
    }

    public function find_all_by_column(array $columns)
    {
        if ($columns) {

            if (! $this->with_deleted) {
                $columns['delete_time'] = null;
            } else {
                unset($columns['delete_time']);
            }

            list($where, $binds) = db_simple_where_sql($columns);

            return $this->find_all_by_sql('select * from `'.$this->table_name."` where $where order by id", $binds);
        } else {
            return $this->find_all();
        }
    }

    protected function find_all_by_condition($condition, array $binds = [])
    {
        return $this->find_all_by_sql('select * from `'.$this->table_name.'` where '.  $condition, $binds);
    }

    // 多条查询内部基方法：查库并回写本地缓存，返回数组 key 为实体 id
    protected function find_all_by_sql($sql_template, array $binds = [])
    {
        $entities = [];

        $rows = db_query($sql_template, $binds, $this->db_config_key);

        foreach ($rows as $row) {
            $entity = local_cache_get($this->class_name, $row['id']);
            if (is_null($entity)) {
                $entity = $this->row_to_entity($row);
                local_cache_set($entity);
            }
            $entities[$entity->id] = $entity;
        }

        return $entities;
    }

    protected function find_all_grouped_entities_by_sql($group_key, $sql_template, array $binds = [])
    {
        $entities = [];

        $rows = db_query($sql_template, $binds, $this->db_config_key);

        foreach ($rows as $row) {
            $entity = local_cache_get($this->class_name, $row['id']);
            if (is_null($entity)) {
                $entity = $this->row_to_entity($row);
                local_cache_set($entity);
            }

            $group_value = $entity->{$group_key};

            if (! isset($entities[$group_value])) {
                $entities[$group_value] = [];
            }
            $entities[$group_value][$entity->id] = $entity;
        }

        return $entities;
    }

    public function find_all_paginated_by_current_page_and_column($current_page, $page_size, array $columns)
    {
        $res = [
            'list' => [],
            'pagination' => [
                'page_size' => $page_size,
                'current_page' => $current_page,
                'count' => 0,
                'pages' => 0,
            ],
        ];

        list($condition, $binds) = db_simple_where_sql($columns);
        // 括号固定条件边界：count 侧在条件前注入 delete_time is null，不加括号时 or 条件会绕过软删除过滤
        $condition = '('.$condition.')';
        $count = $this->count_by_condition($condition, $binds);
        if (! $count) {
            return $res;
        } else {
            $res['pagination']['count'] = $count;
            $res['pagination']['pages'] = ceil($count / $page_size);
        }

        $offset = $page_size * ($current_page - 1);

        // list 侧补上与 count 侧一致的软删除过滤，否则 list 会混入已软删除记录、与 count/pages 对不上
        $res['list'] = $this->find_all_by_condition($condition.$this->with_deleted_and_sql()." limit $offset, $page_size", $binds);

        return $res;
    }

    public function find_all_paginated_by_current_page_and_condition($current_page, $page_size, $condition, array $binds = [])
    {
        $res = [
            'list' => [],
            'pagination' => [
                'page_size' => $page_size,
                'current_page' => $current_page,
                'count' => 0,
                'pages' => 0,
            ],
        ];

        // 括号固定条件边界，理由同 _and_column 变体；list 侧同样补上软删除过滤与 count 侧对齐
        $condition = '('.$condition.')';
        $count = $this->count_by_condition($condition, $binds);
        if (! $count) {
            return $res;
        } else {
            $res['pagination']['count'] = $count;
            $res['pagination']['pages'] = ceil($count / $page_size);
        }

        $offset = $page_size * ($current_page - 1);

        $res['list'] = $this->find_all_by_condition($condition.$this->with_deleted_and_sql()." limit $offset, $page_size", $binds);

        return $res;
    }

    public function count()
    {
        $with_deleted_sql = $this->with_deleted_where_sql();

        $sql = 'select count(*) as count from `'.$this->table_name.'`'.$with_deleted_sql;

        return db_query_value('count', $sql, [], $this->db_config_key);
    }

    protected function count_by_condition($condition, array $binds = [])
    {
        $with_deleted_sql = $this->with_deleted_where_sql_and();

        $sql = 'select count(*) as count from `'.$this->table_name.'`'.$with_deleted_sql.' '.$condition;

        return db_query_value('count', $sql, $binds, $this->db_config_key);
    }

    // 计算实体脏数据：对比 attributes 与 structs，提取变更列，自动递增 version 并刷新 update_time
    private function get_dirty($entity)
    {
        $rows = [];

        foreach ($entity->attributes as $column => $value) {
            if ($entity->structs[$column] !== $value) {
                $rows[$column] = $value;
            }
        }

        $rows['version'] = $entity->version + 1;
        $rows['update_time'] = datetime();
        $rows['delete_time'] = $entity->delete_time;

        return $rows;
    }

    // 将数据库行转换为实体：id/version/create_time/update_time/delete_time 提升为对象属性，其余字段存入 structs(=attributes)
    private function row_to_entity($rows)
    {
        $entity = new $this->class_name();

        $entity->id = $rows['id'];
        $entity->version = $rows['version'];
        $entity->create_time = $rows['create_time'];
        $entity->update_time = $rows['update_time'];
        $entity->delete_time = $rows['delete_time'];

        unset($rows['id']);
        unset($rows['version']);
        unset($rows['create_time']);
        unset($rows['update_time']);
        unset($rows['delete_time']);

        $entity->attributes = $entity->structs = $rows;

        return $entity;
    }

    final public function get_db_config_key()
    {
        return $this->db_config_key;
    }

    // 以下三个 dump_*_sql 方法仅供 Unit of Work 在提交阶段调用，生成待在事务中执行的 SQL
    final public function dump_insert_sql($entity)
    {
        $columns = $values = $binds = [];

        $insert = $entity->attributes + [
            'id' => $entity->id,
            'version' => $entity->version + 1,
            'create_time' => $entity->create_time,
            'update_time' => $entity->update_time,
        ];

        foreach ($insert as $column => $value) {
            $columns[] = $column;
            $values[] = ":$column";
            $binds[":$column"] = $value;
        }

        return [
            'sql_template' => 'insert into `'.$this->table_name.'` (`'.implode('`, `', $columns).'`) values ('.implode(', ', $values).')',
            'binds' => $binds,
        ];
    }

    final public function dump_update_sql($entity)
    {
        $binds = $update = [];

        $binds[':id'] = $entity->id;
        $binds[':old_version'] = $entity->version;

        foreach ($this->get_dirty($entity) as $column => $value) {
            $update[] = "`$column` = :$column";
            $binds[":$column"] = $value;
        }

        return [
            'sql_template' => 'update `'.$this->table_name.'` set '.implode(', ', $update).' where id = :id and version = :old_version',
            'binds' => $binds,
        ];
    }

    final public function dump_delete_sql($entity)
    {
        return [
            'sql_template' => 'delete from `'.$this->table_name.'` where id = :id',
            'binds' => [
                ':id' => $entity->id,
            ],
        ];
    }
}

function dao($class_name, $with_deleted = false)
{
    $dao = instance($class_name.'_dao');
    $dao->set_with_deleted($with_deleted);

    return $dao;
}

// 本地缓存 —— 请求级别的 Identity Map，同一请求内相同实体只从数据库加载一次
function _local_cache_key($entity_type, $id)
{
    return $entity_type.'_'.$id;
}

function _local_cache(?array $cached = null)
{
    static $container = [];

    if (is_null($cached)) {
        return $container;
    }

    return $container = $cached;
}

function local_cache_get($entity_type, $id)
{
    $cached = _local_cache();

    $key = _local_cache_key($entity_type, $id);

    if (isset($cached[$key])) {
        return $cached[$key];
    }

    return;
}

function local_cache_has($entity_type, $id)
{
    $cached = _local_cache();

    $key = _local_cache_key($entity_type, $id);

    return isset($cached[$key]);
}

function local_cache_get_all()
{
    return _local_cache();
}

function local_cache_set(entity $entity)
{
    $cached = _local_cache();

    $key = _local_cache_key(get_class($entity), $entity->id);

    $cached[$key] = $entity;

    _local_cache($cached);
}

function local_cache_delete($entity_type, $id)
{
    $cached = _local_cache();

    $key = _local_cache_key($entity_type, $id);

    unset($cached[$key]);

    _local_cache($cached);
}

function local_cache_delete_all()
{
    _local_cache([]);
}

function local_cache_flush_all()
{
    $cached = _local_cache();

    local_cache_delete_all();

    return $cached;
}

// 从请求参数中获取实体（GET/POST），默认参数名为 {entity_name}_id，返回实体或 null_entity
function input_entity($entity_name, $name = null, $require = false)
{
    if (is_null($name)) {
        $name = $entity_name.'_id';
    }

    if ($id = input($name)) {

        $entity = dao($entity_name)->find_by_id($id);

        otherwise_error_code(strtoupper($entity_name).'_NOT_FOUND', $entity->is_not_null());

        return $entity;
    }

    if ($require) {

        otherwise_error_code(strtoupper($entity_name).'_NOT_FOUND', false);
    } else {
        return null_entity::create($entity_name);
    }
}

// 链式批量加载关系，如 relationship_batch_load($entities, 'creator.orders') 先加载 creator 再加载 orders
function relationship_batch_load($entities, $relationship_chain)
{
    if (empty($entities)) {
        return [];
    }

    if ($entities instanceof entity) {

        $entities = [$entities->id => $entities];
    }

    $relationships = explode('.', $relationship_chain);

    foreach ($relationships as $relationship) {

        if ($entity = reset($entities)) {

            $entities = $entity->relationship_batch_load($relationship, $entities);
        }
    }

    return $entities;
}       
