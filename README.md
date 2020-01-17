# database-transportor

- [安装](#install)
- [使用](#manual)
    - [基础迁移](#basic)
        - [简单迁移](#basic-common)
        - [带默认值的迁移](#basic-with-default)
    - [拆表迁移](#basic)
    - [合表迁移](#basic)
    - [多对多迁移](#basic)

**数据库数据迁移工具。重构数据库后，将旧数据迁移至新库时使用。**

**This tool would be useful if you want to carry the history data after rebuild database.**


<h4 id="install">安装</h4>

`composer require cherrylu/database-transportor`

<h4 id="manual">使用手册</h4>

<h6 id="basic">1.基础迁移</h6>

<p id="basic-common">1.1简单迁移</p>
 
| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>|

```php
    $maps = [
        "new_users_map" => [
        	// 若没有设定target_table的值，会认为该数组的键名'new_users_map'为新表的表名
        	"target_table" => "new_users", 
    	    "original_table" => "old_users",
		    "columns" => [
		    	"id" => "id",
		    	"username" => "name",
		    ],
        ]
    ];
    $old_database = "pgsql";
    $new_database = "mysql";
    
    $transportor = new \cherrylu\transportor\DBT($maps, $new_database, $old_database);
    
    $transportor->setChunk(5000);// 设定每次迁移的数据量 若不设置默认为2000
    
    $transportor->doTransport();// 执行迁移
```


<p id="basic-with-default">1.2带默认值的迁移</p>
 
| 旧表 old_users  || 新表 new_users |
|---|---|---|
| <table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>  |=>|<table><tr><th>id</th><th>username</th><th>nick_name</th></tr><tr><td>1</td><td>张三</td><td></td></tr><tr><td>2</td><td>李四</td><td></td></tr></table>|

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




            

<table>
    <tr>
        <th>id</th>
        <th>name</th>
    </tr>
    <tr>
        <td>1</td>
        <td>张三</td>
    </tr>
    <tr>
        <td>2</td>
        <td>李四</td>
    </tr>
</table>

<table><tr><th>id</th><th>name</th></tr><tr><td>1</td><td>张三</td></tr><tr><td>2</td><td>李四</td></tr></table>

