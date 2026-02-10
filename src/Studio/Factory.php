<?php

/**
 * Studio
 *
 * Use this to create fake data for testing purposes.
 *
 * PHP version 8.3+
 *
 * @package   capile/studio
 * @author    Tecnodesign <ti@tecnodz.com>
 * @license   GNU General Public License v3.0
 * @link      https://tecnodz.com
 */

namespace Studio;

use Studio as S;
use Studio\Exception\AppException;
use Studio\Query;
use Faker\Generator as Faker;

class Factory
{
    const FORMATS = [
        'json',
        'yaml',
        'yml',
        'sql'
    ];

    const TYPE_ERROR = [
        'error' => '[ERROR] ',
        'warning' => '[WARNING] ',
        'info' => '[INFO] '
    ];

    public static
        $debug = false,
        $output = null;

    private static
        $attributes = [],
        $count = 0,
        $origin = null,
        $source = null,
        $sourceData = null,
        $table = '';


    public function __construct($data, $debug = null, $output = null)
    {
        self::$output = $output;

        if ((S_ENV === 'dev' && is_null($debug)) || $debug === true) {
            $this->enableDebug(true);
        }

        if (!$this->isValidFactory($data)) {
            $this->log('Invalid factory configuration.','error');
            return false;
        } else {
            $this->log('Factory initialized.', 'info');

            self::$table = key($data);
            self::$sourceData = $this->getSourceData(self::$source);
            self::$attributes = $data[self::$table]['attributes'] ?? [];
            self::$count = $data[self::$table]['count'] ?? 0;

            if (is_null(self::$sourceData)) {
                $this->log('No source data available.', 'error');
                return false;
            }
            return true;
        }
    }

    public function generateData()
    {
        $this->log('Generating data to ' . self::$table . ' ...', 'info');

        $import = [];
        $records = [];
        $limit = self::$count;
        $i = 0;
        foreach (self::$sourceData as $values) {
            if (self::$attributes) {
                $columns = [];
                foreach (self::$attributes as $attribute => $definition) {
                    if (substr($attribute, 0, 2) == '__') {
                        $columns[$attribute] = $definition;
                        continue;
                    } elseif (isset($values[$definition])) {
                        $columns[$attribute] = $values[$definition];
                        continue;
                    } elseif (preg_match_all('/\{\{([^}]+)\}\}/', $definition, $matches)) {
                        $template = $definition;
                        $replaced = preg_replace_callback('/\{\{([^}]+)\}\}/', function ($m) use ($values) {
                            return $values[$m[1]] ?? $m[0];
                        }, $template);
                        $columns[$attribute] = $replaced;
                        continue;
                    } elseif (is_callable($definition)) {
                        S::log('callable');
                        $columns[$attribute] = call_user_func($definition, $values);
                        continue;
                    } elseif (is_array($definition)) {
                        $columns[$attribute] = $definition[array_rand($definition)];
                        continue;
                    } elseif (class_exists($definition) && is_subclass_of($definition, Query::class)) {
                        S::log('Query class detected: ' . $definition);
                        $columns[$attribute] = new $this($definition);
                    }
                    $columns[$attribute] = $values[$definition] ?? $definition;
                }
                $records[] = $columns;
            } else {
                $records[] = self::$sourceData;
            }
            $i++;
            if ($limit > 0 && $i >= $limit) {
                break;
            }
        }
        if (count($records) > 0) {
            $import[self::$table] = $records;
        }
        S::log($import);
        return $import;
    }

    public static function enableDebug($debug = null)
    {
        if (is_null($debug)) {
            return self::$debug;
        } elseif (is_bool($debug)) {
            self::$debug = (bool)$debug;
            S::$log = self::$debug ? 1 : 0;
        }
    }

    public static function isValidFactory($data)
    {
        if (!is_array($data) || empty($data) || !$src = array_column($data, 'source')) {
            return false;
        }
        self::$source = $src[0];
        return true;
    }

    private function getSourceData($source)
    {
        $src = $source;
        if (preg_match('/\|/', $source)) {
            list($src, self::$origin) = explode('|', $source, 2);
            $source = $src;
        }
        //Check if source type
        if (filter_var($src, FILTER_VALIDATE_URL)) {
           return $this->getRemoteData($src);
        } elseif (is_file($src) || is_file(S_PROJECT_ROOT.'/'.$src)) {
            return $this->getLocalData($src);
        } else {
            $this->log("Source {$src} is not valid.", "error");
        }

        return [];
    }

    private function getRemoteData($url)
    {
        $data = [];
        $curl_handler = curl_init();
        $curl_setopt = [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false
        ];
        try {
            curl_setopt_array($curl_handler, $curl_setopt);
            $response = curl_exec($curl_handler);
            $err = curl_error($curl_handler);
            if ($err) {
                $this->log("cURL Error #:{$err}", "error");
            } else {
                $contentType = curl_getinfo($curl_handler, CURLINFO_CONTENT_TYPE);
                if (strpos($contentType, 'application/json') !== false) {
                    $data = S::unserialize($response, 'json');
                } elseif (strpos($contentType, 'application/x-yaml') !== false || strpos($contentType, 'text/yaml') !== false) {
                    $data = S::unserialize($response, 'yaml');
                } else {
                    $this->log("Unsupported content type from URL: {$contentType}", "error");
                    $data = null;
                }

                if(!is_null($data) && is_array($data[self::$origin])) {
                    $data = $data[self::$origin];
                };
            }
        } catch (AppException $e) {
            $this->log("fetching data from URL: " . $e->getMessage(), "error");
            $data = null;
        }

        return $data;
    }

    private function getLocalData($filePath)
    {
        $data = [];
        if (is_file($filePath)) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        } else {
            $this->log("File $filePath does not exist.", "error");
            return [];
        }
        $this->log("Reading local data from $filePath", 'info');

        if ($this->checkFormat($extension)) {
            switch ($extension) {
                case 'json':
                    $data = S::unserialize(file_get_contents($filePath), 'json');
                    break;
                case 'yaml':
                case 'yml':
                    $data = S::unserialize(file_get_contents($filePath), 'yaml');
                    break;
                case 'sql':
                    $this->log("SQL file detected: {$filePath} / This functionality is not yet implemented.", "warning");
                    //self::$sourceData = file_get_contents($filePath);
                    return false;
                    break;
                default:
                    $data = null;
            }
        } else {
            $this->log("Unsupported file format: {$extension}", "error");
        }

        if (is_null($data)) {
            $this->log("Failed to parse data from $filePath", "error");
        }

        return $data;
    }

    private function log($message, $type = null)
    {
        if (self::$debug) {
            if (!is_null($type) && isset(self::TYPE_ERROR[$type])) {
                $message = self::TYPE_ERROR[$type] . $message;
            }
            S::log($message);
        }
    }

    private function checkFormat($format)
    {
        return in_array(strtolower($format), self::FORMATS);
    }
}