<?php

namespace CherryLu\DatabaseTransportor;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class DBT database transportor
 */
class DBT {

	private $original = "pgsql";

	private $target = "mysql";

	private $preload = [
		"target" => [],
		"original" => [],
	]; // TODO 该属性还没有得到利用

	private $preloadCollection;

	private $chunk = 2000; // 每次迁移的数据量

	/**
	 * @var array
	 */
	private $seed; // 该次迁移需要的seed表 这些seed表在迁移前已经存在

	private $originalLink;
	private $targetLink;

	private $tempColumns = []; // maps 中定义的临时字段
	private $handleFunctions = []; // 迁移完成后需要运行的函数
	private $deleteTempColumns = []; // 完成迁移后需要删除的临时字段
	private $seedTables = []; // 种子表——所有字段都不依赖于其他表的数据表
	//	private $x_map = []; // 每张 target 表需要依赖的 original 表, 以及需要依赖的其他 target 表

	/**
	 * 新旧数据库字段对应关系
	 */
	private $maps = [];

	/**
	 * @var Collection
	 */
	private $originalCollection; // 存放源头数据
	private $insertData; // 存放插入的数据
	private $finished = []; // 已完成迁移的 target 表

	private $safety = 100; // 递归次数限制

	public function __construct(array $maps = [], string $target_db = "mysql", string $original_db = "pgsql", array $preload = []) {
		$this->maps = $maps;
		$this->target = $target_db;
		$this->original = $original_db;
		$this->preload = $preload;

        $original_db && $this->originalLink = DB::connection($original_db);
        $target_db && $this->targetLink = DB::connection($target_db);
	}

	/**
	 * 预加载数据
	 */
	private function preload() {
		foreach ( $this->preload["target"] ?? [] as $table ) {
			$this->preloadCollection["target"][$table] = $this->targetLink->table($table)->get();
		}
		foreach ( $this->preload["original"] ?? [] as $table ) {
			$this->preloadCollection["original"][$table] = $this->originalLink->table($table)->get();
		}
	}

	/**
	 * 执行迁移
	 *
	 * @throws \Exception
	 */
	public function doTransport() {
        if ( empty($this->maps) ) {
            return false;
        } else {
            $this->initDefinition(); // 初始化定义，提取$this->maps中需要的数据
        }

        $this->preload();

		if ( !$this->targetLink || !$this->originalLink ) {
			throw new \Exception("数据库连接错误！");
		}

		$this->checkSeed();

		$this->createTempColumn(); // 创建临时字段

		// 优先迁移种子数据表
		foreach ( $this->seedTables as $seed_table ) {
			$this->transporter($seed_table);
		}

		// 迁移其他表
		foreach ( $this->maps as $table_name => $map ) {
			$this->transporter($table_name);
		}

	}

	private function transporter($table_name, $safety = 0) {
        $safety++;
		if ( $safety >= $this->safety ) {
			throw new \Exception("递归层级过深，请检查代码是否有误！");
		}
		if ( in_array($table_name, $this->seed) || (!empty($this->maps[$table_name]["target_table"]) && in_array($this->maps[$table_name]["target_table"], $this->seed) ) ) {
			return true;
		}
		if ( empty($this->finished[ $table_name ]) ) {
			$map = $this->maps[$table_name];

			if ( !empty($map["transport_after"]) ) {
				$this->transporter($map["transport_after"], ++$safety);
			}

			if ( empty($map["target_table"]) ) {
				$table_true_name = $table_name;
			} else {
				$table_true_name = $map["target_table"];
			}

			if ( !empty($map["middle"]) ) {
				$this->middleExtractor($table_name);
			} elseif ( !empty($this->maps[$table_name]["columns"]) ) {

				$this->insertData[$table_name] = [];
				if ( empty($map["original_table"]) ) {
					$all = 0;
					$this->originalCollection[$table_name] = new Collection([]);
				} else {
					$all = $this->originalLink->table($map["original_table"]);
					if ( !empty($map["extra_conditions"]) ) {
						foreach ( $map["extra_conditions"] as $extra_condition ) {
							if ( is_string($extra_condition) ) {
								$all = $all->whereRaw($extra_condition);
							} else {
								$all = $this->queryComposer($all, $extra_condition[0], $extra_condition[1], $extra_condition[2]);
							}
						}
					}
					$all = $all->count();
				}

				for ( $i = 0; $i < $all; $i = $i + $this->chunk) {
					$this->originalCollection[$table_name] = $this->originalLink->table($map["original_table"]);
					if ( !empty($map["extra_conditions"]) ) {
						foreach ( $map["extra_conditions"] as $extra_condition ) {
							if ( is_string($extra_condition) ) {
								$this->originalCollection[$table_name] = $this->originalCollection[$table_name]->whereRaw($extra_condition);
							} else {
								$this->originalCollection[$table_name] = $this->queryComposer($this->originalCollection[$table_name], $extra_condition[0], $extra_condition[1], $extra_condition[2]);
							}
						}
					}
					empty($map["order"]) || $this->originalCollection[$table_name] = $this->originalCollection[$table_name]->orderBy($map["order"]["order_by"], $map["order"]["direction"] ?? "asc");
					$this->originalCollection[$table_name] = $this->originalCollection[$table_name]
						->skip($i)->take($this->chunk)
						->get();
					foreach ( $map["columns"] as $target_column => $column_define ) {
						if ( empty($column_define) ) {
							continue;
						}
						if ( is_string($column_define) ) { // 普通映射
							$this->sourceExtractor($table_name, ["original"=>$column_define], $target_column);
						} elseif ( is_array($column_define) ) {
							if ( count($column_define) === 1 && isset($column_define["default"]) ) { // 字段设定默认值
								$this->dataInjector($table_name, $target_column, $column_define["default"]);
							} elseif ( !empty($column_define["original"]) ) {
								$this->sourceExtractor($table_name, $column_define, $target_column);
							} elseif ( !empty($column_define["refer"]) ) { // 字段关联其他表
								if ( $column_define["refer"]["search_source"] == "target" && $column_define["refer"]["search_table"] && empty($this->finished[$column_define["refer"]["search_table"]]) ) {
									$this->transporter($column_define["refer"]["search_table"], ++$safety);
								}
								$refers[$target_column] = $column_define["refer"];
							}
						} elseif ( !empty($column_define["refers"]) ) {
                            if ( $column_define["refers"]["search_source"] == "target" && $column_define["refers"]["search_table"] && empty($this->finished[$column_define["refers"]["search_table"]]) ) {
                                $this->transporter($column_define["refers"]["search_table"], $safety);
                            }
                            $multi_refers[$target_column] = $column_define["refers"];
                        }
					}

                    if ( isset($multi_refers) ) {
                        foreach ( $multi_refers as $target_column => $multi_refer ) {
                            $this->multiReferExtractor($table_name, $target_column, $multi_refer);
                        }
                    }

					if ( isset($refers) ) {
						foreach ( $refers as $target_column => $refer ) {
							$this->referExtractor($table_name,$target_column, $refer);
						}
					}

					if ( isset($this->insertData[$table_name]) ) {
						$this->targetLink->table($table_true_name)->insert($this->insertData[$table_name]);
						// 去除已迁移数据内存
						unset($this->insertData[$table_name]);
						unset($this->originalCollection[$table_name]);
					}
				}
			} else {
				throw new \Exception("字段定义不能为空");
			}

			// 标记为完成
			return $this->finished[ $table_name ] = true;
		} else {
			return true;
		}
	}

    public function multiReferExtractor($target_table, $target_column, $multi_refer) {
        $default_value = $this->maps[$target_table]["columns"][$target_column]["default"] ?? null;

        if ( $multi_refer["search_source"] == "target" ) {
            if ( empty($this->finished[$multi_refer["search_table"]]) ) {
                $this->transporter($multi_refer["search_table"]);
            }
            $table_true_name = $this->maps[$multi_refer["search_table"]]["target_table"] ?? $multi_refer["search_table"];
            $refer_query = $this->targetLink->table($table_true_name);
        } else {
            $refer_query = $this->originalLink->table($multi_refer["search_table"]);
        }
        if ( !empty($multi_refer["extra_conditions"]) ) {
            $refer_query = $this->nestQuery($refer_query, $multi_refer["extra_conditions"]);
        }

        $according_data = array_unique(Arr::pluck($this->insertData[$target_table], $multi_refer["according_column"]));
        if ( !empty($multi_refer["pre_format"]) ) {
            foreach ( $according_data as $key => $according_datum ) {
                $according_data[$key] = $multi_refer["pre_format"]($according_datum);
            }
        }
        $refer_data = $refer_query->whereIn($multi_refer["search_column"], $according_data)
                                  ->get()
                                  ->groupBy($multi_refer["search_column"]);
        $format_data = [];
        foreach ( $refer_data as $key => $datum ) {
            $format_data[rtrim($key)] = $datum;
        }
        foreach ( $this->insertData[ $target_table ] as &$insert_datum ) {
            if ( empty($refer["pre_format"]) ) {
                $according_datum = rtrim($insert_datum[$refer["according_column"]]);
            } else {
                $according_datum = $refer["pre_format"](rtrim($insert_datum[$refer["according_column"]]));
            }
            if ( is_null($format_data[ $according_datum ]) ) {
                $insert_datum[ $target_column ] = $default_value;
            } else {
                $insert_datum[ $target_column ] = $refer["processor"]($format_data[ $according_datum ]);
            }
        }unset($insert_datum);
    }

    /**
	 * @param $target_table
	 * @param $target_column
	 * @param $refer
	 *
	 * @throws \Exception
	 */
	private function referExtractor($target_table, $target_column, $refer) {
		$default_value = $this->maps[$target_table]["columns"][$target_column]["default"] ?? null;

		if ( $refer["search_source"] == "target" ) {
			if ( empty($this->finished[$refer["search_table"]]) ) {
				$this->transporter($refer["search_table"]);
			}
			$table_true_name = $this->maps[$refer["search_table"]]["target_table"] ?? $refer["search_table"];
			$refer_query = $this->targetLink->table($table_true_name);
		} else {
			$refer_query = $this->originalLink->table($refer["search_table"]);
		}
		if ( !empty($refer["extra_conditions"]) ) {
			$refer_query = $this->nestQuery($refer_query, $refer["extra_conditions"]);
		}

		if ( is_string($refer["according_column"]) ) {
			$according_data = array_unique(Arr::pluck($this->insertData[$target_table], $refer["according_column"]));
			if ( !empty($refer["pre_format"]) ) {
				foreach ( $according_data as $key => $according_datum ) {
					$according_data[$key] = $refer["pre_format"]($according_datum);
				}
			}
			$refer_data = $refer_query->whereIn($refer["search_column"], $according_data)
			                          ->get([$refer["wanted_column"], $refer["search_column"]])
			                          ->pluck($refer["wanted_column"], $refer["search_column"])
			                          ->toArray();
			$format_data = [];
			foreach ( $refer_data as $key => $datum ) {
				$format_data[rtrim($key)] = $datum;
			}
			foreach ( $this->insertData[ $target_table ] as &$insert_datum ) {
                if ( isset($refer["pre_format"]) ) { // 查找前对according进行预格式化
                    $according_datum = $refer["pre_format"](rtrim($insert_datum[$refer["according_column"]]));
                } else {
                    $according_datum = rtrim($insert_datum[$refer["according_column"]]);
                }
                if ( isset($refer["format_wanted"]) ) { // 查找后wanted进行格式化
                    $insert_datum[ $target_column ] = ($refer["format_wanted"]($format_data[ $according_datum ])) ?? $default_value;
                } else {
                    $insert_datum[ $target_column ] = $format_data[ $according_datum ] ?? $default_value;
                }
			}unset($insert_datum);
		} else {
			foreach ( $this->insertData[ $target_table ] as &$insert_datum ) {
				$temp_refer_query = clone $refer_query;
				foreach ( $refer["according_column"] as $num => $according_column ) {
					if ( empty($refer["pre_format"]) ) {
						$according_datum = $insert_datum[$according_column];
					} else {
						$according_datum = $refer["pre_format"]($insert_datum[$according_column]);
					}
					$temp_refer_query = $this->queryComposer($temp_refer_query, $refer["search_column"][$num], "=", $according_datum);
				}
				$refer_data = $temp_refer_query->first([$refer["wanted_column"]]);
				$wanted_column = $refer["wanted_column"];
				if ( $refer_data ) {
				    if ( isset($refer["format_wanted"]) ) {
                        $insert_datum[ $target_column ] = $refer["format_wanted"]($refer_data->$wanted_column);
				    } else {
                        $insert_datum[ $target_column ] = $refer_data->$wanted_column;
                    }
				} else {
                    $insert_datum[ $target_column ] = null;
                }
			}unset($insert_datum);
		}
	}

	private function nestQuery(Builder $query, array $conditions) {
		foreach ( $conditions as $condition ) {
			if ( is_string($condition) ) {
				$query = $query->whereRaw($condition);
			} else {
				$query = $this->queryComposer($query, $condition[0], $condition[1], $condition[2]);
			}
		}
		return $query;
	}

	private function queryComposer(Builder $query, $search_column, $method, $search_value) {
		$method = str_replace(" ", "", strtolower($method));
		switch ( $method ) {
			case "=" :

			case ">" :
			case "<" :
			case "<>" :
			case "!=" :
				if ( is_null($search_value) ) {
					if ( in_array($method, ["<>", "!="]) ) {
						$query = $query->whereNotNull($search_column);
					} elseif ($method == "=") {
						$query = $query->whereNull($search_column);
					} else {
						$err = "筛选条件有误！ " . "search_column：$search_column; method：$method; search_value：$search_value sql：".$query->toSql();
						throw new \Exception($err);
					}
				} else {
					$query = $query->where($search_column, $method, $search_value);
				}
				break;
			case "like" : $query = $query->where($search_column, "like", $search_value); break;
			case "notlike" : $query = $query->whereRaw($search_column . " NOT LIKE '" . $search_value . "'"); break;
			case "notin" : $query = $query->whereNotIn($search_column, $search_value); break;
			case "in" : $query = $query->whereIn($search_column, $search_value); break;
			case "between" : $query = $query->whereBetween($search_column, $search_value); break;
			case "notbetween" : $query = $query->whereNotBetween($search_column, $search_value); break;
			default : break;
		}
		return $query;
	}

	private function sourceExtractor($target_table, $column_define, $target_column) {
		$default_value = $column_define["default"] ?? null;
		$original_column = $column_define["original"] ?? "";
		foreach ( $this->originalCollection[$target_table] as $i => $source ) {
			$data = null;
			if ( isset($column_define["function"]) ) { // 执行自定义处理函数
				if ( isset($column_define["affection"]) ) { // 填入当前字段受影响的其他字段的值
					$data = $column_define["function"]($source);
					if ( count($column_define["affection"]) == count($column_define["affection"],1) ) {
						$this->insertData[$target_table][$i][$column_define["affection"]["target_column"]] = $data[$column_define["affection"]["source_key"]];
					} else {
						foreach ( $column_define["affection"] as $item ) {
							$this->insertData[$target_table][$i][$item["target_column"]] = $data[$item["source_key"]];
						}
					}
					$data = $data[$column_define["original"]] ?? $default_value;
				} else {
					$data = ($column_define["function"]($source)) ?? $default_value;
				}
			} else {
				$data = $source->$original_column ?? $default_value;
			}
			$this->insertData[$target_table][$i][$target_column] = $data;
		}
	}

	private function dataInjector($target_table, $target_column, $value) {
		$len = $this->originalCollection[$target_table]->count();
		for ( $i = 0; $i < $len; $i++) {
			$this->insertData[$target_table][$i][$target_column] = $value;
		}
	}

	private function createTempColumn($is_retry = false) {
		if ( $this->tempColumns ) {
			foreach ( $this->tempColumns as $columns ) {
				foreach ( $columns as $column ) {
					if ( Schema::connection($this->target)->hasColumn($column["table"], $column["column"]) ) {
						if ( $column["rebuild"] ) {
							Schema::connection($this->target)->table($column["table"], function (Blueprint $table) use ($column) {
								$table->dropColumn($column["column"]);
							});
						} else {
							throw new \Exception(" {$column['table']}表中存在同名字段{$column['column']} ");
						}
					}
					Schema::connection($this->target)->table($column["table"], function (Blueprint $table) use ($column) {
						$table->string($column["column"])->nullable();
					});
					$this->deleteTempColumns[] = $column;
				}
			}
		} else {
			$this->initDefinition();
			$is_retry || $this->createTempColumn(true);
		}
	}

	private function middleExtractor($target_table) {
		$table_true_name = $this->maps[$target_table]["target_table"] ?? $target_table;
		$this->insertData[$target_table] = [];
		$middle = $this->maps[$target_table]["middle"];
		$one_fill_column = $middle["one"]["fill_column"];
		$one_wanted_column = $middle["one"]["wanted_column"];
		$many_fill_column = $middle["many"]["fill_column"];
		$many_wanted_column = $middle["many"]["wanted_column"];
		if ( empty($middle["one"]["refer_source"]) || $middle["one"]["refer_source"] == "target" ) {
			if ( empty($this->finished[$middle["one"]["refer_table"]]) ) {
				$this->transporter($middle["one"]["refer_table"]);
			}
			$all = $this->targetLink->table($middle["one"]["refer_table"])->count();
			$one_link = clone $this->targetLink;
		} else {
			$all = $this->originalLink->table($middle["one"]["refer_table"])->count();
			$one_link = clone $this->targetLink;
		}

		if ( empty($middle["many"]["refer_source"]) || $middle["many"]["refer_source"] == "target" ) {
			if ( empty($this->finished[$middle["many"]["refer_table"]]) ) {
				$this->transporter($middle["many"]["refer_table"]);
			}
			$refer_link = clone $this->targetLink;
		} else {
			$refer_link = clone $this->originalLink;
		}

		for ( $i = 0; $i < $all; $i = $i + $this->chunk ) {
			$one_data = $one_link->table($middle["one"]["refer_table"])
			                     ->skip($i)
			                     ->take($this->chunk)
			                     ->get([$middle["one"]["wanted_column"], $middle["one"]["according_column"]]);
			foreach ( $one_data as $one_datum ) {
				$according_column = $middle["one"]["according_column"];
				$according_column = $one_datum->$according_column;
				if ( empty($according_column) ) {
					continue;
				}
				if ( !empty($middle["one"]["pre_format"]) ) {
					$according_column = $middle["one"]["pre_format"]($according_column);
				}
				$refer_data = $this->queryComposer($refer_link->table($middle["many"]["refer_table"]), $middle["many"]["search_column"], $middle["many"]["search_method"], $according_column)
				                   ->get([$middle["many"]["wanted_column"]]);
				foreach ( $refer_data as $refer_datum ) {
					$this->insertData[$target_table][] = [
						$one_fill_column => $one_datum->$one_wanted_column,
						$many_fill_column => $refer_datum->$many_wanted_column,
					];
				}
			}
			if ( isset($this->insertData[$target_table]) ) {
				$this->targetLink->table($table_true_name)->insert($this->insertData[$target_table]);
				// 去除已迁移数据内存
				unset($this->insertData[$target_table]);
			}
		}
	}

	/**
	 * 检查seed表中是否都有数据
	 *
	 * @throws \Exception
	 */
	private function checkSeed() {
		foreach ( $this->seed as $seed ) {
			if ( DB::connection($this->target)->table($seed)->count() == 0 ) {
				throw new \Exception("$seed 中没有初始数据！");
			}
		}
	}

	/**
	 * @param mixed $maps
	 */
	public function setMaps($maps): void {
		$this->maps = $maps;
	}

    /**
     * @param string $original
     */
    public function setOriginal(string $original): void {
        $this->original = $original;
        $this->originalLink = DB::connection($original);
    }

    /**
     * @param string $target
     */
    public function setTarget(string $target): void {
        $this->target = $target;
        $this->targetLink = DB::connection($target);
    }

	/**
	 * @param array $preload
	 */
	public function setPreload(array $preload): void {
		$this->preload = $preload;
	}

	/**
	 * @param int $chunk
	 */
	public function setChunk(int $chunk): void {
		$this->chunk = $chunk;
	}

	/**
	 * @param mixed $seed
	 */
	public function setSeed(array $seed): void {
		$this->seed = $seed;
	}

	/**
	 * @param int $safety
	 */
	public function setSafety(int $safety): void {
		$this->safety = $safety;
	}

	private function initDefinition() {
		$this->tempColumns = [];
		$this->seedTables = [];
		foreach ( $this->maps as $table_name => $map ) {
			$is_seed = true;
			foreach ( $map["columns"] as $column_name => $define ) {
				if ( empty($define) ) {
					continue;
				}
				if (!empty($map["middle"])) {
					$is_seed = false;
				}
				if ( is_array($define) ) {
					if ( !empty($define["delete_after_transport"]) ) { // 提取迁移后需要删除的字段(临时字段)
						$delete_table = empty($map["target_table"]) ? $table_name : $map["target_table"];
						$this->tempColumns[$delete_table][] = ["table"=>$delete_table, "column" => $column_name, "rebuild" => !empty($define["rebuild"]) ];
					}
					if ( !empty($define["refer"]) ) {
						$is_seed = false;
					}
					//					$this->x_map[ $table_name ]["refer_table"] = $define["refer"]["search_table"];
				} else {
					//
				}
			}
			empty($map["run_after_transport"]) || $this->handleFunctions[] = $map["run_after_transport"];
			$is_seed && $this->seedTables[] = $table_name;
		}
	}

	/**
	 * 导入完成后删除临时字段
	 */
	public function __destruct() {
		foreach ( $this->handleFunctions as $handle_function ) {
			$handle_function();
		}
		foreach ( $this->deleteTempColumns as $columns ) {
			Schema::connection($this->target)->table($columns["table"], function (Blueprint $table) use ($columns) {
				$table->dropColumn($columns["column"]);
			});
		}
	}

}
