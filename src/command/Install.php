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
use think\admin\service\ModuleService;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

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
            'rules' => ['const.php', 'version', 'app', 'config', 'extend', 'h5', 'public', 'route', 'stage', 'system', 'vendor'],
            'ignore' => []
        ]
    ];

    protected function configure()
    {
        $this->setName('xadmin:install');
        $this->addArgument('name', Argument::OPTIONAL, 'ModuleName', 'base');
        $this->setDescription("Source code Install and Update for ThinkAdmin");
    }

    protected function execute(Input $input, Output $output)
    {

        if ($this->installFile(true) !== true) {
            $this->output->writeln("更新文件出错，请联系开发人员");
            return false;
        }

        $this->output->writeln("更新数据库内容");
        if ($this->installData() !== true) {
            $this->output->writeln("更新数据库出错，请联系开发人员");
            return false;
        }

        $this->installSuccess();

//        $this->name = trim($input->getArgument('name'));
//        if (empty($this->name)) {
//            $this->output->writeln('Module name of online installation cannot be empty');
//        } elseif ($this->name === 'all') {
//            foreach ($this->bind as $bind) {
//                $this->rules = array_merge($this->rules, $bind['rules']);
//                $this->ignore = array_merge($this->ignore, $bind['ignore']);
//            }
//            [$this->installFile(true), $this->installData()];
//        } elseif (isset($this->bind[$this->name])) {
//            $this->rules = $this->bind[$this->name]['rules'] ?? [];
//            $this->ignore = $this->bind[$this->name]['ignore'] ?? [];
//            [$this->installFile(true), $this->installData()];
//        } else {
//            $this->output->writeln("The specified module {$this->name} is not configured with installation rules");
//        }
    }

    /**
     * @title 写入文件
     * @param bool $write
     */
    private function installFile($write = true)
    {
        $module = ModuleService::instance();
        $data = $module->grenerateDifference();
        if (empty($data)) {
            $this->output->writeln('没有需要更新的文件');
        } else {
            [$total, $used] = [count($data), 0];
            foreach ($data as $file) {
                if ($write) {
                    [$state, $mode, $name, $error] = $module->updateFileByDownload($file);
                    if ($state) {
                        if ($mode === 'add') $this->queue->message($total, ++$used, "--- {$name} 新增 成功");
                        if ($mode === 'mod') $this->queue->message($total, ++$used, "--- {$name} 更新 成功");
                        if ($mode === 'del') $this->queue->message($total, ++$used, "--- {$name} 删除 成功");
                    } else {
                        if ($mode === 'add') $this->queue->message($total, ++$used, "--- {$name} 新增 失败 {$error}");
                        if ($mode === 'mod') $this->queue->message($total, ++$used, "--- {$name} 更新 失败 {$error}");
                        if ($mode === 'del') $this->queue->message($total, ++$used, "--- {$name} 删除 失败 {$error}");
                    }
                } else {
                    $this->queue->message($total, ++$used, "--- {$file['name']} --- {$file['type']}");
                }
            }
        }
        return true;
    }

    protected function installData()
    {
        return include ROOT_PATH . DIRECTORY_SEPARATOR . 'update.php';
    }

    protected function installSuccess()
    {
        unlink(VERSION_FILE);
    }

}
