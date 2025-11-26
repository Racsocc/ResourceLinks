$(document).ready(function () {
    // 检查是否存在编辑器
    if ($('#text').length === 0) return;

    // 初始化数据
    var resources = (typeof resourceData !== 'undefined') ? resourceData : [];

    // 构建 DOM
    var $container = $('<div class="resource-manager" id="resource-manager"></div>');
    $container.append('<h3>资源链接</h3>');

    var $list = $('<ul class="resource-list" id="resource-list"></ul>');
    $container.append($list);

    var $form = $('<div class="resource-form" id="resource-form"></div>');
    $form.html(`
        <h4>添加/编辑资源</h4>
        <input type="hidden" id="res-id" value="">
        <div class="form-group">
            <label>类型</label>
            <select id="res-type">
                <option value="link">普通链接</option>
                <option value="pan">云盘链接</option>
                <option value="repo">代码仓库</option>
            </select>
        </div>
        <div class="form-group">
            <label>标题</label>
            <input type="text" id="res-title" placeholder="例如：项目官网">
        </div>
        <div class="form-group">
            <label>链接</label>
            <input type="text" id="res-url" placeholder="https://...">
        </div>
        <div class="form-group pan-field" style="display:none;">
            <label>提取码</label>
            <input type="text" id="res-code" placeholder="选填">
        </div>
        <div class="form-group">
            <label>选项</label>
            <label style="width:auto;font-weight:normal;"><input type="checkbox" id="res-new-window" checked> 新窗口打开</label>
        </div>
        <div style="text-align:right;">
            <button type="button" class="resource-btn" id="btn-save-res">保存资源</button>
            <button type="button" class="resource-btn" id="btn-clear-res" style="background:#ccc;color:#333;">清空</button>
        </div>
    `);
    $container.append($form);

    // 隐藏域用于提交数据
    var $hiddenInput = $('<input type="hidden" name="resources_json" id="resources_json">');
    $('form[name="write_post"], form[name="write_page"]').append($hiddenInput);

    // 可见性设置
    var $visibilityContainer = $('<div class="resource-visibility"></div>');
    $visibilityContainer.html(`
        <label><strong>资源可见性：</strong></label>
        <select name="fields[resources_visibility]" id="resources_visibility">
            <option value="inherit">跟随全局设置</option>
            <option value="visible">公开</option>
            <option value="reply_required">回复后可见</option>
        </select>
    `);
    $container.append($visibilityContainer);

    // 恢复可见性设置 (从 custom fields 中读取，如果存在)
    // Typecho 的自定义字段通常在页面上有 input，我们需要找到它并同步值，或者如果通过 fields[...] 提交，Typecho 会自动处理
    // 但是我们需要知道当前的值来初始化 select
    // 简单的做法：检查页面上是否已经有名为 fields[resources_visibility] 的输入框
    // Typecho 默认自定义字段可能会渲染在下面。为了避免冲突，我们可以尝试查找已有的值。
    // 但是更稳妥的是，我们在 renderAdmin 中把这个值传过来。

    if (typeof resourceVisibility !== 'undefined') {
        $('#resources_visibility').val(resourceVisibility);
    }

    // 插入到编辑器下方
    $('#text').closest('p').after($container);

    // 渲染列表
    function renderList() {
        $list.empty();
        resources.forEach(function (res, index) {
            var typeLabel = {
                'link': '链接',
                'pan': '云盘',
                'repo': '仓库'
            }[res.type] || res.type;

            var $li = $('<li class="resource-item" data-index="' + index + '" draggable="true"></li>');
            $li.html(`
                <span class="handle" style="cursor:move;padding:0 10px;color:#999;">☰</span>
                <div class="info">
                    <strong>[${typeLabel}]</strong> ${res.title}
                    <div style="font-size:0.85em;color:#666;">${res.url}</div>
                </div>
                <div class="actions">
                    <button type="button" class="resource-btn edit-res">编辑</button>
                    <button type="button" class="resource-btn delete delete-res">删除</button>
                </div>
            `);
            $list.append($li);

            // Native DnD Events
            var el = $li[0];
            el.addEventListener('dragstart', handleDragStart, false);
            el.addEventListener('dragenter', handleDragEnter, false);
            el.addEventListener('dragover', handleDragOver, false);
            el.addEventListener('dragleave', handleDragLeave, false);
            el.addEventListener('drop', handleDrop, false);
            el.addEventListener('dragend', handleDragEnd, false);
        });
        updateHiddenInput();

        // 绑定事件
        $('.edit-res').click(function () {
            var index = $(this).closest('li').data('index');
            loadResource(index);
        });

        $('.delete-res').click(function () {
            if (confirm('确定删除此资源？')) {
                var index = $(this).closest('li').data('index');
                resources.splice(index, 1);
                renderList();
            }
        });
    }

    // Drag and Drop Handlers
    var dragSrcEl = null;

    function handleDragStart(e) {
        dragSrcEl = this;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.innerHTML);
        this.classList.add('moving');
    }

    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        e.dataTransfer.dropEffect = 'move';
        return false;
    }

    function handleDragEnter(e) {
        this.classList.add('over');
    }

    function handleDragLeave(e) {
        this.classList.remove('over');
    }

    function handleDrop(e) {
        if (e.stopPropagation) {
            e.stopPropagation();
        }

        if (dragSrcEl != this) {
            // Swap logic is tricky for complex content. 
            // Better to move the element in DOM.

            // Determine position
            var list = this.parentNode;
            var allItems = Array.prototype.slice.call(list.children);
            var srcIndex = allItems.indexOf(dragSrcEl);
            var targetIndex = allItems.indexOf(this);

            if (srcIndex < targetIndex) {
                this.after(dragSrcEl);
            } else {
                this.before(dragSrcEl);
            }
        }
        return false;
    }

    function handleDragEnd(e) {
        this.classList.remove('moving');
        var items = $list.find('li');
        items.removeClass('over');

        // Reorder resources array based on new DOM order
        var newResources = [];
        items.each(function () {
            var oldIndex = $(this).data('index');
            newResources.push(resources[oldIndex]);
        });

        resources = newResources;
        // Re-render to fix indices
        renderList();
    }

    function updateHiddenInput() {
        $('#resources_json').val(JSON.stringify(resources));
    }

    function loadResource(index) {
        var res = resources[index];
        $('#res-id').val(index);
        $('#res-type').val(res.type).trigger('change');
        $('#res-title').val(res.title);
        $('#res-url').val(res.url);
        $('#res-code').val(res.code || '');
        $('#res-new-window').prop('checked', res.new_window == 1);
        $('#btn-save-res').text('更新资源');
    }

    // 类型切换
    $('#res-type').change(function () {
        if ($(this).val() == 'pan') {
            $('.pan-field').show();
        } else {
            $('.pan-field').hide();
        }
    });

    // 保存/添加
    $('#btn-save-res').click(function () {
        var index = $('#res-id').val();
        var type = $('#res-type').val();
        var title = $('#res-title').val();
        var url = $('#res-url').val();

        if (!title || !url) {
            alert('标题和链接必填');
            return;
        }

        var res = {
            type: type,
            title: title,
            url: url,
            code: $('#res-code').val(),
            new_window: $('#res-new-window').is(':checked') ? 1 : 0
        };

        if (index !== '') {
            // 更新
            resources[index] = res;
        } else {
            // 新增
            resources.push(res);
        }

        renderList();
        clearForm();
    });

    $('#btn-clear-res').click(clearForm);

    function clearForm() {
        $('#res-id').val('');
        $('#res-type').val('link').trigger('change');
        $('#res-title').val('');
        $('#res-url').val('');
        $('#res-code').val('');
        $('#res-new-window').prop('checked', true);
        $('#btn-save-res').text('添加资源');
    }

    // 初始渲染
    renderList();
});
