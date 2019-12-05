<?php

namespace voskobovich\tree\manager\widgets\nestable;

use voskobovich\tree\manager\interfaces\TreeInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\bootstrap\ActiveForm;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\widgets\Pjax;

/**
 * Class Nestable
 * @package voskobovich\tree\manager\widgets
 */
class Nestable extends Widget
{
    /**
     * @var string
     */
    public $id;

    /**
     * @var array
     */
    public $modelClass;

    /**
     * @var array
     */
    public $nameAttribute = 'name';

    /**
     * Behavior key in list all behaviors on model
     * @var string
     */
    public $behaviorName = 'nestedSetsBehavior';

    /**
     * @var array.
     */
    public $pluginOptions = [];

    /**
     * Url to MoveNodeAction
     * @var string
     */
    public $moveUrl;

    /**
     * Url to CreateNodeAction
     * @var string
     */
    public $createUrl;

    /**
     * Url to UpdateNodeAction
     * @var string
     */
    public $updateUrl;

    /**
     * Url to page additional update model
     * @var string
     */
    public $advancedUpdateRoute;

    /**
     * Url to DeleteNodeAction
     * @var string
     */
    public $deleteUrl;

    /**
     * Handler for render form fields on create new node
     * @var callable
     */
    public $formFieldsCallable;

    /**
     * @var string the name of the default view when [[\yii\web\ViewAction::$viewParam]] GET parameter is not provided
     * by user. Defaults to 'index'. This should be in the format of 'path/to/view', similar to that given in the
     * GET parameter.
     * @see \yii\web\ViewAction::$viewPrefix
     */
    public $defaultView = '@vendor/voskobovich/yii2-tree-manager/src/widgets/nestable/views/index';

    /**
     * @var \Closure
     */
    public $prepareNode;

    /**
     * Структура меню в php array формате
     * @var array
     */
    private $_items = [];

    /**
     * Инициализация плагина
     */
    public function init()
    {
        parent::init();

        if (empty($this->id)) {
            $this->id = $this->getId();
        }

        if ($this->modelClass == null) {
            throw new InvalidConfigException('Param "modelClass" must be contain model name');
        }

        if (null == $this->behaviorName) {
            throw new InvalidConfigException("No 'behaviorName' supplied on action initialization.");
        }

        if (null == $this->advancedUpdateRoute && ($controller = Yii::$app->controller)) {
            $this->advancedUpdateRoute = "{$controller->id}/update";
        }

        if ($this->formFieldsCallable == null) {
            $this->formFieldsCallable = function ($form, $model) {
                /** @var ActiveForm $form */
                echo $form->field($model, $this->nameAttribute);
            };
        }

        /** @var ActiveRecord|TreeInterface $model */
        $model = $this->modelClass;

        /** @var ActiveRecord[]|TreeInterface[] $rootNodes */
        $rootNodes = $model::find()->roots()->all();

        $nodes = [];

        foreach ($rootNodes as $rootNode){
            /** @var ActiveRecord|TreeInterface $node */
            $node = $rootNode->populateTree();
            $nodes = array_merge($nodes, $this->prepareItems($node));
        }

        $this->_items = $nodes;
    }

    /**
     * @param ActiveRecord|TreeInterface $node
     * @return array
     */
    protected function getNode($node)
    {
        $items = [];

        $id = $node->getPrimaryKey();
        $item = [
            'id' => $id,
            'name' => $node->getAttribute($this->nameAttribute),
            'children' => $this->getChildren($node),
            'update-url' => Url::to([$this->advancedUpdateRoute, 'id' => $node->getPrimaryKey()]),
        ];
        if(isset($this->prepareNode) && is_callable([$this, 'prepareNode'])) {
            $item = call_user_func_array($this->prepareNode, ['item' => $item, 'node' => $node]);
        }
        $items[$id] = $item;

        return $items;
    }

    protected function getChildren($node)
    {
        $items = [];

        /** @var ActiveRecord[]|TreeInterface[] $children */
        $children = $node->children;

        if($children) {
            foreach ($children as $child) {
                $id = $child->getPrimaryKey();
                $item = [
                    'id' => $id,
                    'name' => $child->getAttribute($this->nameAttribute),
                    'children' => $this->getChildren($child),
                    'update-url' => Url::to([$this->advancedUpdateRoute, 'id' => $child->getPrimaryKey()]),
                ];
                if(isset($this->prepareNode) && is_callable([$this, 'prepareNode'])) {
                    $item = call_user_func_array($this->prepareNode, ['item' => $item, 'node' => $node]);
                }
                $items[$id] = $item;
            }
        }

        return $items;
    }

    /**
     * @param ActiveRecord|TreeInterface[] $node
     * @return array
     */
    protected function prepareItems($node)
    {
        return $this->getNode($node);
    }

    /**
     * @param null $name
     * @return array
     */
    protected function getPluginOptions($name = null)
    {
        $options = ArrayHelper::merge($this->getDefaultPluginOptions(), $this->pluginOptions);

        if (isset($options[$name])) {
            return $options[$name];
        }

        return $options;
    }

    /**
     * Работаем!
     */
    public function run()
    {
        $this->registerActionButtonsAssets();
        $this->actionButtons();

        Pjax::begin([
            'id' => $this->id . '-pjax'
        ]);
        $this->registerPluginAssets();
        echo $this->render($this->defaultView, ['items' => $this->_items] );
        $this->renderForm();
        Pjax::end();

        $this->actionButtons();
    }

    /**
     * Register Asset manager
     */
    protected function registerPluginAssets()
    {
        NestableAsset::register($this->getView());

        $view = $this->getView();

        $pluginOptions = $this->getPluginOptions();
        $pluginOptions = Json::encode($pluginOptions);
        $view->registerJs("$('#{$this->id}').nestable({$pluginOptions});");
        // language=JavaScript
        $view->registerJs("
			$('#{$this->id}-new-node-form').on('beforeSubmit', function(e){
                $.ajax({
                    url: '{$this->getPluginOptions('createUrl')}',
                    method: 'POST',
                    data: $(this).serialize(),
                    success: function(data, textStatus, jqXHR) {
	                    $('#{$this->id}-new-node-modal').modal('hide')
	                    $.pjax.reload({container: '#{$this->id}-pjax'});
	                    window.scrollTo(0, document.body.scrollHeight);
                    },
                }).fail(function (jqXHR) {
                    alert(jqXHR.responseText);
                });

                return false;
			});
		");
    }

    /**
     * Register Asset manager
     */
    protected function registerActionButtonsAssets()
    {
        $view = $this->getView();
        $view->registerJs("
			$('.{$this->id}-nestable-menu [data-action]').on('click', function(e) {
                e.preventDefault();

				var target = $(e.target),
				    action = target.data('action');

				switch (action) {
					case 'expand-all':
					    $('#{$this->id}').nestable('expandAll');
					    $('.{$this->id}-nestable-menu [data-action=\"expand-all\"]').hide();
					    $('.{$this->id}-nestable-menu [data-action=\"collapse-all\"]').show();

						break;
					case 'collapse-all':
					    $('#{$this->id}').nestable('collapseAll');
					    $('.{$this->id}-nestable-menu [data-action=\"expand-all\"]').show();
					    $('.{$this->id}-nestable-menu [data-action=\"collapse-all\"]').hide();

						break;
				}
			});
		");
    }

    /**
     * Generate default plugin options
     * @return array
     */
    protected function getDefaultPluginOptions()
    {
        $options = [
            'namePlaceholder' => $this->getPlaceholderForName(),
            'deleteAlert' => Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable',
                'The nobe will be removed together with the children. Are you sure?'),
            'newNodeTitle' => Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable',
                'Enter the new node name'),
        ];

        $controller = Yii::$app->controller;
        if ($controller) {
            $options['moveUrl'] = Url::to(["{$controller->id}/moveNode"]);
            $options['createUrl'] = Url::to(["{$controller->id}/createNode"]);
            $options['updateUrl'] = Url::to(["{$controller->id}/updateNode"]);
            $options['deleteUrl'] = Url::to(["{$controller->id}/deleteNode"]);
        }

        if ($this->moveUrl) {
            $this->pluginOptions['moveUrl'] = $this->moveUrl;
        }
        if ($this->createUrl) {
            $this->pluginOptions['createUrl'] = $this->createUrl;
        }
        if ($this->updateUrl) {
            $this->pluginOptions['updateUrl'] = $this->updateUrl;
        }
        if ($this->deleteUrl) {
            $this->pluginOptions['deleteUrl'] = $this->deleteUrl;
        }

        return $options;
    }

    /**
     * Get placeholder for Name input
     */
    public function getPlaceholderForName()
    {
        return Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable', 'Node name');
    }

    /**
     * Кнопки действий над виджетом
     */
    public function actionButtons()
    {
        echo Html::beginTag('div', ['class' => "{$this->id}-nestable-menu"]);

        echo Html::beginTag('div', ['class' => 'btn-group']);
        /*echo Html::button(Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable', 'Add node'), [
            'data-toggle' => 'modal',
            'data-target' => "#{$this->id}-new-node-modal",
            'class' => 'btn btn-success'
        ]);*/
        echo Html::button(Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable', 'Collapse all'), [
            'data-action' => 'collapse-all',
            'class' => 'btn btn-default'
        ]);
        echo Html::button(Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable', 'Expand all'), [
            'data-action' => 'expand-all',
            'class' => 'btn btn-default',
            'style' => 'display: none'
        ]);
        echo Html::endTag('div');

        echo Html::endTag('div');
    }

    /**
     * Render form for new node
     */
    protected function renderForm()
    {
        /** @var ActiveRecord $model */
        $model = new $this->modelClass;
        $labelNewNode = Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable','New node');
        $labelCloseButton = Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable','Close');
        $labelCreateNode = Yii::t('vendor/voskobovich/yii2-tree-manager/widgets/nestable','Create node');

        echo <<<HTML
<div class="modal" id="{$this->id}-new-node-modal" tabindex="-1" role="dialog" aria-labelledby="newNodeModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
HTML;
        /** @var ActiveForm $form */
        $form = ActiveForm::begin([
            'id' => $this->id . '-new-node-form'
        ]);

        echo <<<HTML
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="newNodeModalLabel">$labelNewNode</h4>
      </div>
      <div class="modal-body">
HTML;

        echo call_user_func($this->formFieldsCallable, $form, $model);

        echo <<<HTML
        <input id="category-parent-id" name="parentId" type="hidden" value="">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">$labelCloseButton</button>
        <button type="submit" class="btn btn-primary">$labelCreateNode</button>
      </div>
HTML;
        $form->end();
        echo <<<HTML
    </div>
  </div>
</div>
HTML;
    }

}
