<?php

// +----------------------------------------------------------------------
// | Library for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2020 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 仓库地址 ：https://gitee.com/zoujingli/ThinkLibrary
// | github 仓库地址 ：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace think\admin\service;

use app\common\library\Qcloud;
use think\admin\extend\HttpExtend;
use think\admin\extend\Parsedown;
use think\admin\Library;
use think\admin\Service;
use think\facade\Cache;

const CACHE_CLOUD_UPDATE_TIME = 300;
const CACHE_CLOUD_NEWST_FILES = 'cloud_newst_files';
const CACHE_UPDATE_DIFF_CONTRAST = 'cache_update_diff_contrast';

/**
 * 系统模块管理
 * Class ModuleService
 * @package think\admin\service
 */
class ModuleService extends Service
{
    /**
     * 代码根目录
     * @var string
     */
    protected $root;

    /**
     * 官方应用地址
     * @var string
     */
    protected $server;

    /**
     * 指定更新的分支
     * @var
     */
    protected $branch = 'rc';

    /**
     * 官方应用版本
     * @var string
     */
    protected $version;

    /**
     * 仅尝试，不真正写入，测试用
     * @var bool
     */
    protected $is_try = false;

    /**
     * 模块服务初始化
     */
    public function initialize()
    {
        $this->root = $this->app->getRootPath();
        $this->version = trim(Library::VERSION, 'v');
    }

    /**
     * 获取版本号信息
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @param bool $try
     * @return $this
     */
    public function try(bool $try = true): ModuleService
    {
        $this->is_try = $try;
        return $this;
    }

    /**
     * 指定分支
     * @param string $branch
     * @return ModuleService
     */
    public function branch(string $branch): ModuleService
    {
        $this->branch = $branch;
        return $this;
    }

    /**
     * 获取模块变更
     * @return array
     */
    public function change(): array
    {
        [$online, $locals] = [$this->online(), $this->getModules()];
        foreach ($online as &$item) if (isset($locals[$item['name']])) {
            $item['local'] = $locals[$item['name']];
            if ($item['local']['version'] < $item['version']) {
                $item['type_code'] = 2;
                $item['type_desc'] = '需要更新';
            } else {
                $item['type_code'] = 3;
                $item['type_desc'] = '无需更新';
            }
        } else {
            $item['type_code'] = 1;
            $item['type_desc'] = '未安装';
        }
        return $online;
    }


    /**
     * 获取线上模块数据
     * @return array
     */
    public function online(): array
    {
        $data = $this->app->cache->get('moduleOnlineData', []);
        if (!empty($data)) return $data;
        $result = json_decode(HttpExtend::get($this->server . '/admin/api.update/version'), true);
        if (isset($result['code']) && $result['code'] > 0 && isset($result['data']) && is_array($result['data'])) {
            $this->app->cache->set('moduleOnlineData', $result['data'], 30);
            return $result['data'];
        } else {
            return [];
        }
    }

    /**
     * 安装或更新模块
     * @return array
     */
//    public function install(): array
//    {
//        $this->app->cache->set('moduleOnlineData', []);
//        $data = $this->grenerateDifference();
//        if (empty($data)) return [0, '没有需要安装的文件', []];
//        $lines = [];
//        foreach ($data as $file) {
//            [$state, $mode, $name] = $this->updateFileByDownload($file);
//            if ($state) {
//                if ($mode === 'add') $lines[] = "add $name successed";
//                if ($mode === 'mod') $lines[] = "modify $name successed";
//                if ($mode === 'del') $lines[] = "deleted $name successed";
//            } else {
//                if ($mode === 'add') $lines[] = "add $name failed";
//                if ($mode === 'mod') $lines[] = "modify $name failed";
//                if ($mode === 'del') $lines[] = "deleted $name failed";
//            }
//        }
//        return [1, '模块安装成功', $lines];
//    }

    /**
     * 获取系统模块信息
     * @param array $data
     * @return array
     */
    public function getModules(array $data = []): array
    {
        $service = NodeService::instance();
        foreach ($service->getModules() as $name) {
            $vars = $this->_getModuleVersion($name);
            if (is_array($vars) && isset($vars['version']) && preg_match('|^\d{4}\.\d{2}\.\d{2}\.\d{2}$|', $vars['version'])) {
                $data[$name] = array_merge($vars, ['change' => []]);
                foreach ($service->scanDirectory($this->_getModuleInfoPath($name) . 'change', [], 'md') as $file) {
                    $data[$name]['change'][pathinfo($file, PATHINFO_FILENAME)] = Parsedown::instance()->parse(file_get_contents($file));
                }
            }
        }
        return $data;
    }

    /**
     * 获取文件信息列表
     * @param array $rules 文件规则
     * @param array $ignore 忽略规则
     * @param array $data 扫描结果列表
     * @return array
     */
    public function getChanges(array $rules, array $ignore = [], array $data = []): array
    {
        // 扫描规则文件
        foreach ($rules as $rule) {
            $path = $this->root . strtr(trim($rule, '\\/'), '\\', '/');
            $data = array_merge($data, $this->_scanLocalFileHashList($path));
        }
        // 清除忽略文件
        foreach ($data as $key => $item) foreach ($ignore as $ign) {
            if (stripos($item['name'], $ign) === 0) unset($data[$key]);
        }
        // 返回文件数据
        return ['rules' => $rules, 'ignore' => $ignore, 'list' => $data];
    }

    /**
     * 检查文件是否可下载
     * @param string $name 文件名称
     * @return boolean
     */
    public function checkAllowDownload(string $name): bool
    {
        // 禁止目录上跳级别
        if (stripos($name, '..') !== false) {
            return false;
        }
        // 禁止下载压缩包文件
        if (stripos($name, '.tar.gz') !== false) {
            return false;
        }
        // 阻止可能存在敏感信息的文件被下载
        if (preg_match('#config[\\\\/]+(filesystem|database|session|cache)#i', $name)) {
            return false;
        }
        return true;
        // 检查允许下载的文件规则列表
        // foreach ($this->_getAllowDownloadRule() as $rule) {
        //     if (stripos($name, $rule) === 0) return true;
        // }
        // 不在允许下载的文件规则
        // return false;
    }

    /**
     * 获取文件差异数据
     * @return array
     */
    public function grenerateDifference(): array
    {
        $result = [];
        /** 获取线上最新文件列表及缓存处理 */
        $online = cache(CACHE_CLOUD_NEWST_FILES);
        if (empty($online)) {
            $online = Qcloud::newestFiles($this->branch);
            Cache::tag('update_package')->clear();
            Cache::tag('update_package')->set(CACHE_CLOUD_NEWST_FILES, $online, CACHE_CLOUD_UPDATE_TIME);
        }

        /** 线上数据是否错误 */
        $is_error = isset($online->error) && !empty($online->error);
        if ($is_error) return ['error' => $online->error];

        /** 获取检查远程列表 */
        $remote = $online->getData();
        $check_remote_list = isset($remote['list']) && count($remote['list']) > 1000;
        if ($check_remote_list === false) {
            Cache::tag('update_package')->clear();
            return [];
        }

        /** 获取文件差异列表并缓存 */
        $contrast = cache(CACHE_UPDATE_DIFF_CONTRAST);
        if (empty($contrast)) {
            $local = $this->getChanges($remote['rules'] ?? [], $remote['ignore'] ?? []);
            $contrast = $this->_grenerateDifferenceContrast($remote['list'], $local['list']);
            Cache::tag('update_package')->set(CACHE_UPDATE_DIFF_CONTRAST, $contrast, CACHE_CLOUD_UPDATE_TIME);
        }

        foreach ($contrast as $file) {
            if (in_array($file['type'], ['add', 'del', 'mod'])) foreach ($remote['rules'] as $rule) {
                if (stripos($file['name'], $rule) === 0) $result[] = $file;
            }
        }
        return $result;
    }

    /**
     * 尝试下载并更新文件
     * @param array $file 文件信息
     * @return array|void
     */
    public function updateFileByDownload(array $file): array
    {
        if (in_array($file['type'], ['add', 'mod'])) {
            $down = $this->_downloadUpdateFile(encode($file['name']));
            if (isset($down['error'])) {
                return [false, $file['type'], $file['name'], $down['error']];
            } else {
                return [true, $file['type'], $file['name'], $down['message'] ?? ''];
            }
        } elseif (in_array($file['type'], ['del'])) {
            $real = $this->root . $file['name'];
            if ($this->is_try) return [true, $file['type'], $file['name'], ''];
            if (is_file($real) && unlink($real)) {
                $this->_removeEmptyDirectory(dirname($real));
                return [true, $file['type'], $file['name'], ''];
            } else {
                return [false, $file['type'], $file['name'], ''];
            }
        }
    }

    /**
     * 获取模块版本信息
     * @param string $name 模块名称
     * @return bool|array|null
     */
    private function _getModuleVersion(string $name)
    {
        $filename = $this->_getModuleInfoPath($name) . 'module.json';
        if (file_exists($filename) && is_file($filename) && is_readable($filename)) {
            $vars = json_decode(file_get_contents($filename), true);
            return isset($vars['name']) && isset($vars['version']) ? $vars : null;
        } else {
            return false;
        }
    }

    /**
     * 下载更新文件内容
     * @param string $encode
     * @return array|false
     */
    private function _downloadUpdateFile(string $encode)
    {
        $result = Qcloud::downRemoteFile($encode, $this->branch);
        if (isset($result->error) && !empty($result->error)) return false;
        $filename = $this->root . decode($encode);
        if ($this->is_try === false) {
            /** 文件夹不存在则创建 */
            if (empty(file_exists(dirname($filename)))) mkdir(dirname($filename), 0755, true);
            $writed = file_put_contents($filename, gzuncompress(base64_decode($result->getData()['content'])));
            if (empty($writed)) {
                return ['error' => '文件写入失败'];
            } else {
                return ['message' => number_format(($writed / 1024), 2) . 'kb'];
            }
        }
        return [];
    }

    /**
     * 清理空目录
     * @param string $path
     */
    private function _removeEmptyDirectory(string $path)
    {
        if (is_dir($path) && count(scandir($path)) === 2 && rmdir($path)) {
            $this->_removeEmptyDirectory(dirname($path));
        }
    }

    /**
     * 获取模块信息路径
     * @param string $name 模块名称
     * @return string
     */
    private function _getModuleInfoPath(string $name): string
    {
        $appdir = $this->app->getBasePath() . $name;
        return $appdir . DIRECTORY_SEPARATOR . 'module' . DIRECTORY_SEPARATOR;
    }

    /**
     * 根据线上线下生成操作数组
     * @param array $serve 线上文件数据
     * @param array $local 本地文件数据
     * @param array $diffy 计算结果数据
     * @return array
     */
    private function _grenerateDifferenceContrast(array $serve = [], array $local = [], array $diffy = []): array
    {
        $serve = array_combine(array_column($serve, 'name'), array_column($serve, 'hash'));
        $local = array_combine(array_column($local, 'name'), array_column($local, 'hash'));
        foreach ($serve as $name => $hash) {
            $type = isset($local[$name]) ? ($hash === $local[$name] ? null : 'mod') : 'add';
            $diffy[$name] = ['type' => $type, 'name' => $name];
        }
        foreach ($local as $name => $hash) if (!isset($serve[$name])) {
            $diffy[$name] = ['type' => 'del', 'name' => $name];
        }
        ksort($diffy);
        return array_values($diffy);
    }

    /**
     * 获取目录文件列表
     * @param mixed $path 扫描目录
     * @return array
     */
    private function _scanLocalFileHashList(string $path): array
    {
        $data = [];
        foreach (NodeService::instance()->scanDirectory($path, [], null) as $file) {
            if ($this->checkAllowDownload($name = substr($file, strlen($this->root)))) {
                $data[] = ['name' => $name, 'hash' => md5(preg_replace('/\s+/', '', file_get_contents($file)))];
            }
        }
        return $data;
    }
}
