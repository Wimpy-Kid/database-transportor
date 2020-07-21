# database-transportor

**数据库数据迁移工具。重构数据库后，将旧数据迁移至新库时使用。**
**This tool would be useful if you want to carry the history data after rebuild database.**

### 目录
-  [安装](#install)
-  [使用](#manual)
  1. [基础迁移](#basic)
    1. [一对一迁移](#basic-common)
    2. [带默认值的迁移 - default](#basic-with-default)
    3. [迁移前的预处理 - function](#basic-preformat)
	4. [带查询条件的迁移 - extra_conditions](#basic-extra-conditions)
  2. [引用迁移](#refer)
	1.  [单引用迁移 - refer](#refer-single)
	2.  [多引用迁移 - refers](#refer-mulit)
  3. [拆表迁移](#basic)
  4. [合表迁移](#basic)
  5. [多对多迁移](#basic)
<br /><br />

<h3 id="install">安装</h3>

`composer require cherrylu/database-transportor`<br /><br /><br />

<h3 id="manual">使用</h3>

<h4 id="basic">1.基础迁移</h4>

<p id="basic-common"></p>

##### 1.1一对一迁移

 
| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>|

```php
  $maps = [
	// new_users_map 默认的新表名称
    "new_users_map" => [
      // 指定的新表名称，若没有设定target_table的值，会认为该数组的键名'new_users_map'为新表的表名
      "target_table" => "new_users",
      // 旧表名称，即数据来源表的表名
      "original_table" => "old_users",
      // 新旧表字段映射关系 '新表字段' => '旧表字段'
      "columns" => [
        "id" => "id",
        "username" => "name",
      ],
    ]
  ];
	
	// database.php 中设的 connections 设定
  $old_database = "pgsql";
  $new_database = "mysql";
  
  $transportor = new \cherrylu\transportor\DBT($maps, $new_database, $old_database);
  
  $transportor->setChunk(5000);// 设定每次迁移的数据量 若不设置默认为2000
  
  $transportor->doTransport();// 执行迁移
```

<br /><br />

<p id="basic-with-default"></p>

##### 1.2带默认值的迁移
 
| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th><th>created_at</th></tr><tr><td>1</td><td>张三</td><td>now()</td></tr><tr><td>2</td><td>李四</td><td>now()</td></tr></table>|

```php
  $maps = [
    "new_users" => [
      "original_table" => "old_users",
      "columns" => [
        "id" => "id",
        "username" => "name",
        "created_at" => ["default" => \Carbon\Carbon::now()]
      ],
    ]
  ];
```
> 当旧表字段查询结果为`NULL`，或处理后结果为`NULL`时将填入 `default`设定的值

<br /><br />


<p id="basic-preformat"></p>

##### 1.3迁移前的预处理

| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th></tr><tr><td>1</td><td>张三-1</td></tr><tr><td>2</td><td>李四-2</td></tr></table>|

```php
  $maps = [
    "new_users" => [
      "original_table" => "old_users",
      "columns" => [
        "id" => "id",
        "username" => [
          "original" => "name",
          "function" => function ($data) {
            return $data->name . "-" .$data->id;
          }
        ]
      ],
    ]
  ];
```

<br /><br />

<p id="basic-extra-conditions"></p>

##### 1.4带查询条件的迁移

| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr><tr><td>3</td><td>王五</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>|

```php
  $maps = [
    "new_users" => [
      "original_table" => "old_users",
      "extra_conditions" => [
        ["name", "<>", "王五"],
        // or 定义为字符串时，即执行原生的查询
        " `name` <> '王五' ",
      ],
      "columns" => [
      	"id" => "id",
        "username" => "name",
      ],
    ]
  ];
```

该工具类所有查询操作符如下：

```php
	case "="
	case ">"
	case "<"
	case "<>"
	case "!="
	case "like"
	case "notlike"
	case "notin"
	case "in"
	case "between"
	case "notbetween"
```

<br /><br />



<h4 id="refer">2.引用迁移</h4>

<p id="refer-single"></p>

新表 new_roles:

| id  |  role_name  |
| ------------ | ------------ |
|  1 |  管理员  |
|  2 |  用户  |

| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id<th>name<th>role_name<tr><td>1<td>张三<td>管理员<tr><td>2<td>李四<td>用户<tr><td>3<td>王五<td>黑户</table>  |=>|<table><tr><th>id<th>name<th>role_id<tr><td>1<td>张三<td>1<tr><td>2<td>李四<td>2<tr><td>3<td>王五<td>0</table>|

##### 2.1 单引用迁移 - refer

```php
  $maps = [
    "new_users" => [
      "original_table" => "old_users",
      "columns" => [
        "id"  => "id",
        "username" => "name",
        "role_id" => [
          "refer" => [ // 单引用
            "search_source" => "target", // 如果数据源为旧表时，用original即可
            "search_table" => "client_file_types",
            "search_column" => "name_en",
            "according_column" => "temp_file_title",
            "wanted_column" => "id",
            
            // 未定义此项时，直接迁移 id 的原值。$data是固定格式，为 "wanted_column" 定义的对应引用字段，此处为 id
            "pre_format" => function ($data) {
                // 查询前去除前后的无关字符
                return trim($data->role_name);
            },
          ]
        ],
        "default" => 0,
      ],
    ]
  ];
```

<br /><br />

<p id="refer-multi"></p>

##### 2.2 多引用迁移 - refers
新表 accounts:

| id  |  user_id  | fee_type_id | amount |
| ------------ | ------------ | ------------ | ------------ |
|  1 |  1  | 1 | 100 |
|  2 |  1  | 2 | 200 |

| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th><tr><td>1<td>张三</td></table>  |=>|<table><tr><th>id</th><th>name</th><th>amount</th><tr><td>1</td><td>张三</td><td>300</td></table>|

```php
$maps = [
  "new_users" => [
    "original_table" => "old_users",
    "columns" => [
      "id"  => "id",
      "username" => "name",
      "amount" => [
        "refers" => [
          "according_column" => "id",
          "search_source" => "target",
          "search_table" => "accounts",
          "search_column" => "user_id",
          "processor" => function ($data) {
            $amount = 0;
            foreach ( $data as $datum ) {
              $amount += $datum->amount;
            }
            return $amount;
          }
        ]
      ],
    ]
  ]
];
```

