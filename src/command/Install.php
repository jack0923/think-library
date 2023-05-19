<?php

// +----------------------------------------------------------------------
// | 轻云网络
// +----------------------------------------------------------------------
// | Copyright (c) 2021 东莞市轻云网络科技有限公司 All rights reserved.
// +----------------------------------------------------------------------
// | Author: jack <105351345@qq.com>
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace think\admin\command;

use think\admin\Command;
use think\admin\service\AdminService;
use think\admin\service\ModuleService;
use think\admin\service\SystemService;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Cache;

/**
 * 插件更新安装指令
 * Class Install
 * @package think\admin\command
 */
class Install extends Command
{

    /**
     * 指定模块名称
     * @var string
     */
    protected $name;

    /**
     * 查询规则
     * @var array
     */
    protected $rules = [];

    /**
     * 忽略规则
     * @var array
     */
    protected $ignore = [];

    /**
     * 规则配置
     * @var array
     */
    protected $bind = [
        'base' => [
            'rules'  => ['const.php', 'version', 'app', 'config', 'extend', 'h5', 'public', 'route', 'stage', 'system', 'vendor'],
            'ignore' => []
        ]
    ];

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('xadmin:install');
        $this->addArgument('name', Argument::OPTIONAL, 'ModuleName', 'base');
        $this->setDescription("Source code Install and Update for ThinkAdmin");
    }

    /**
     * 执行更新操作
     * @param Input $input
     * @param Output $output
     * @return bool
     */
    protected function execute(Input $input, Output $output): bool
    {
        if ($this->installFile() !== true) {
            $this->output->writeln("更新文件出错，请联系开发人员");
            return false;
        }
        $this->output->writeln("更新数据库内容");
        if ($this->installData() !== true) {
            $this->output->writeln("更新数据库出错，请联系开发人员");
            return false;
        }
        return $this->installSuccess();
    }

    /**
     * @title 写入文件
     * @return bool
     */
    private function installFile(): bool
    {
        $module = ModuleService::instance()->try(false);
        $update_to_beta = $this->queue->data['update_to_beta'] ?? 0;
        $module->branch($update_to_beta ? 'beta' : 'rc');
        $data = $module->grenerateDifference();
        if (empty($data)) {
            $this->output->writeln('没有需要更新的文件');
        } else {
            [$total, $used] = [count($data), 0];
            foreach ($data as $file) {
                [$state, $mode, $name, $message] = $module->updateFileByDownload($file);
                if ($state) {
                    if ($mode === 'add') $this->queue->message($total, ++$used, "--- 新增 $message");
                    if ($mode === 'mod') $this->queue->message($total, ++$used, "--- 更新 $message");
                    if ($mode === 'del') $this->queue->message($total, ++$used, "--- 删除 $message");
                } else {
                    if ($mode === 'add') $this->queue->message($total, ++$used, "--- $name 新增 失败 $message");
                    if ($mode === 'mod') $this->queue->message($total, ++$used, "--- $name 更新 失败 $message");
                    if ($mode === 'del') $this->queue->message($total, ++$used, "--- $name 删除 失败 $message");
                }
            }
        }
        return true;
    }

    /**
     * 更新数据库文件
     * @return mixed
     */
    protected function installData()
    {
        return include ROOT_PATH . DIRECTORY_SEPARATOR . 'update.php';
    }

    /**
     * 安装成功后处理
     */
    protected function installSuccess(): bool
    {
        AdminService::instance()->clearCache();
        SystemService::instance()->clearRuntime();
        Cache::tag('update_package')->clear();
        $this->output->writeln("清除缓存");
        unlink(VERSION_FILE);
        return true;
    }

}
