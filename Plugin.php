<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 资源链接插件，可添加官网、云盘、仓库等资源链接
 * 
 * @package ResourceLinks
 * @author RacsoCC
 * @version 1.0.0
 * @link http://typecho.org
 */
class ResourceLinks_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName = $db->getAdapterName();

        // 创建资源链接表
        if (strpos($adapterName, 'Mysql') !== false) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}resource_links` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `post_id` int(10) unsigned NOT NULL DEFAULT '0',
                `type` enum('link','pan','repo') NOT NULL DEFAULT 'link',
                `platform` varchar(32) DEFAULT NULL,
                `title` varchar(128) DEFAULT NULL,
                `url` varchar(512) DEFAULT NULL,
                `code` varchar(32) DEFAULT NULL,
                `new_window` tinyint(1) DEFAULT '1',
                `nofollow` tinyint(1) DEFAULT '0',
                `display_code_mode` enum('plain','mask','copy_only') DEFAULT 'plain',
                `sort` int(10) DEFAULT '0',
                `created_at` int(10) unsigned DEFAULT '0',
                `updated_at` int(10) unsigned DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `post_id` (`post_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            try {
                $db->query($sql);
            } catch (Typecho_Db_Exception $e) {
                throw new Typecho_Plugin_Exception('创建数据表失败: ' . $e->getMessage());
            }
        } else {
            throw new Typecho_Plugin_Exception('仅支持 MySQL 数据库');
        }

        // 注册钩子
        // 在文章编辑页底部注入 JS/CSS
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('ResourceLinks_Plugin', 'renderAdmin');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('ResourceLinks_Plugin', 'renderAdmin');

        // 保存文章时保存资源
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('ResourceLinks_Plugin', 'saveResources');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('ResourceLinks_Plugin', 'saveResources');

        // 文章内容渲染
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('ResourceLinks_Plugin', 'render');
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('ResourceLinks_Plugin', 'render');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        // 可以在这里选择是否删除数据表，通常保留
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $blockTitle = new Typecho_Widget_Helper_Form_Element_Text('blockTitle', NULL, '资源', _t('区块标题'), _t('文章底部资源区块的标题'));
        $form->addInput($blockTitle);

        $displayStyle = new Typecho_Widget_Helper_Form_Element_Radio(
            'displayStyle',
            array('list' => _t('列表'), 'card' => _t('卡片')),
            'list',
            _t('显示风格'),
            _t('选择资源展示的风格')
        );
        $form->addInput($displayStyle);

        $showIcons = new Typecho_Widget_Helper_Form_Element_Radio(
            'showIcons',
            array('1' => _t('显示'), '0' => _t('不显示')),
            '1',
            _t('显示图标'),
            _t('是否显示资源类型的图标')
        );
        $form->addInput($showIcons);

        $visibility = new Typecho_Widget_Helper_Form_Element_Radio(
            'visibility',
            array('visible' => _t('公开'), 'reply_required' => _t('回复后可见')),
            'visible',
            _t('默认可见性'),
            _t('全局默认资源可见性策略')
        );
        $form->addInput($visibility);
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 在后台编辑页渲染资源管理界面
     * 
     * @access public
     * @param mixed $post
     * @return void
     */
    public static function renderAdmin($post)
    {
        // 引入 CSS 和 JS，增加时间戳参数
        $pluginUrl = Helper::options()->pluginUrl . '/ResourceLinks/';
        $version = '1.0.1'; // 可以根据需要修改版本号，或者使用 time()
        echo '<link rel="stylesheet" href="' . $pluginUrl . 'assets/css/admin.css?v=' . $version . '">';

        // 获取当前文章的资源数据
        $resources = [];
        $cid = -1;

        // $post 可能是 Widget_Contents_Post_Edit 对象或者其他
        // 在 admin/write-post.php 中，$post 通常是页面变量
        // 我们需要获取 cid。
        // 如果是在 write-post.php 页面，可以通过 url 参数 cid 获取，或者通过 global $post (不推荐)

        $request = Typecho_Request::getInstance();
        $cid = $request->get('cid');
        $resourceVisibility = 'inherit';

        if ($cid) {
            $db = Typecho_Db::get();
            $prefix = $db->getPrefix();
            $resources = $db->fetchAll($db->select()->from($prefix . 'resource_links')
                ->where('post_id = ?', $cid)
                ->order('sort', Typecho_Db::SORT_ASC));

            // 获取可见性设置 (从 custom fields)
            $row = $db->fetchRow($db->select('str_value')->from($prefix . 'fields')
                ->where('cid = ?', $cid)
                ->where('name = ?', 'resources_visibility'));
            if ($row) {
                $resourceVisibility = $row['str_value'];
            }
        }

        // 将资源数据传递给 JS
        echo '<script>';
        echo 'var resourceData = ' . json_encode($resources) . ';';
        echo 'var resourceVisibility = "' . $resourceVisibility . '";';
        echo '</script>';

        echo '<script src="' . $pluginUrl . 'assets/js/admin.js"></script>';
    }

    /**
     * 保存资源数据
     * 
     * @access public
     * @param array $contents 文章内容
     * @param Typecho_Widget_Helper_Edit $edit
     * @return void
     */
    public static function saveResources($contents, $edit)
    {
        $cid = $edit->cid;
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $resourcesJson = Typecho_Request::getInstance()->get('resources_json');

        if ($resourcesJson) {
            $resources = json_decode($resourcesJson, true);

            // 先删除旧的资源
            $db->query($db->delete($prefix . 'resource_links')->where('post_id = ?', $cid));

            // 插入新的资源
            if (is_array($resources)) {
                foreach ($resources as $index => $res) {
                    $data = array(
                        'post_id' => $cid,
                        'type' => $res['type'],
                        'platform' => isset($res['platform']) ? $res['platform'] : '',
                        'title' => $res['title'],
                        'url' => $res['url'],
                        'code' => isset($res['code']) ? $res['code'] : '',
                        'new_window' => isset($res['new_window']) ? (int)$res['new_window'] : 1,
                        'nofollow' => isset($res['nofollow']) ? (int)$res['nofollow'] : 0,
                        'display_code_mode' => isset($res['display_code_mode']) ? $res['display_code_mode'] : 'plain',
                        'sort' => $index,
                        'created_at' => time(),
                        'updated_at' => time()
                    );
                    $db->query($db->insert($prefix . 'resource_links')->rows($data));
                }
            }
        }
    }

    /**
     * 渲染文章内容
     * 
     * @access public
     * @param string $content
     * @param Widget_Abstract_Contents $widget
     * @param string $lastResult
     * @return string
     */
    public static function render($content, $widget, $lastResult)
    {
        $content = $lastResult;

        // 获取资源
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $resources = $db->fetchAll($db->select()->from($prefix . 'resource_links')
            ->where('post_id = ?', $widget->cid)
            ->order('sort', Typecho_Db::SORT_ASC));

        if (empty($resources)) {
            return $content;
        }

        // 获取可见性设置
        // 1. 单篇设置 (尝试从 widget 获取，如果失败则查库)
        $postVisibility = 'inherit';

        // 尝试直接获取自定义字段
        if (isset($widget->resources_visibility)) {
            $postVisibility = $widget->resources_visibility;
        } else {
            $fieldRow = $db->fetchRow($db->select('str_value')->from($prefix . 'fields')
                ->where('cid = ?', $widget->cid)
                ->where('name = ?', 'resources_visibility'));
            if ($fieldRow) {
                $postVisibility = $fieldRow['str_value'];
            }
        }

        // 2. 全局设置
        $globalVisibility = Helper::options()->plugin('ResourceLinks')->visibility;

        $isVisible = true;

        if ($postVisibility === 'reply_required' || ($postVisibility === 'inherit' && $globalVisibility === 'reply_required')) {
            $isVisible = false;

            // 检查是否登录用户
            $user = Typecho_Widget::widget('Widget_User');
            if ($user->hasLogin()) {
                $isVisible = true;
            } else {
                // 检查是否评论过
                $commenterMail = Typecho_Cookie::get('__typecho_remember_mail');
                if ($commenterMail) {
                    $hasComment = $db->fetchRow($db->select('coid')->from($prefix . 'comments')
                        ->where('cid = ?', $widget->cid)
                        ->where('mail = ?', $commenterMail)
                        ->where('status = ?', 'approved')
                        ->limit(1));
                    if ($hasComment) {
                        $isVisible = true;
                    }
                }
            }
        }

        // 构建 HTML
        $pluginUrl = Helper::options()->pluginUrl . '/ResourceLinks/';
        $displayStyle = Helper::options()->plugin('ResourceLinks')->displayStyle;
        $showIcons = Helper::options()->plugin('ResourceLinks')->showIcons;

        $listClass = 'resource-list';
        if ($displayStyle == 'card') {
            $listClass .= ' card-mode';
        }

        $version = '1.0.3'; // Update version
        $html = '<link rel="stylesheet" href="' . $pluginUrl . 'assets/css/resource.css?v=' . $version . '">';
        $html .= '<script src="' . $pluginUrl . 'assets/js/resource.js"></script>';
        $html .= '<div class="resource-plugin-box">';
        $html .= '<h5>' . Helper::options()->plugin('ResourceLinks')->blockTitle . '</h5>';

        if ($isVisible) {
            $html .= '<ul class="' . $listClass . '">';
            foreach ($resources as $res) {
                $iconHtml = '';
                if ($showIcons) {
                    $iconType = $res['type'];
                    // Simple SVG icons
                    $svgs = [
                        'link' => '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
                        'pan' => '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 15v4c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2v-4M17 9l-5 5-5-5M12 12.8V2.5"/></svg>',
                        'repo' => '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path></svg>'
                    ];
                    $iconSvg = isset($svgs[$iconType]) ? $svgs[$iconType] : $svgs['link'];
                    $iconHtml = '<span class="resource-icon type-' . $iconType . '">' . $iconSvg . '</span>';
                }

                $html .= '<li class="resource-item type-' . $res['type'] . '">';
                $html .= '<a href="' . $res['url'] . '" target="_blank" class="resource-link">';
                $html .= $iconHtml;
                $html .= '<span class="resource-title">' . $res['title'] . '</span>';
                $html .= '</a>';

                if ($res['type'] == 'pan' && !empty($res['code'])) {
                    $html .= '<div class="resource-meta">';
                    $html .= ' <span class="resource-code">提取码: ' . $res['code'] . '</span>';
                    $html .= ' <button class="resource-copy-btn" data-code="' . $res['code'] . '">复制</button>';
                    $html .= '</div>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= '<div class="resource-locked">';
            $html .= '<p>此处内容需要评论本文后才能查看。</p>';
            $html .= '<a href="#comments" class="resource-btn-lock">前往评论</a>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $content . $html;
    }
}
