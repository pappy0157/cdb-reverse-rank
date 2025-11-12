(function(wp){
  const { registerBlockType } = wp.blocks;
  const { InspectorControls } = wp.blockEditor || wp.editor;
  const { PanelBody, TextControl, SelectControl } = wp.components;
  const el = wp.element.createElement;

  registerBlockType('cdb/reverse-rank', {
    title: 'CDB 逆アクセスランキング',
    icon: 'chart-bar',
    category: 'widgets',
    attributes: {
      range: { type: 'string', default: '7d' },
      type: { type: 'string', default: 'domain' },
      limit: { type: 'number', default: 50 }
    },
    edit: function(props){
      const a = props.attributes;
      return el('div', { className:'cdb-rr-block-editor'},
        el(InspectorControls, {},
          el(PanelBody, { title:'設定' },
            el(SelectControl, {
              label:'期間',
              value:a.range,
              options:[
                {label:'1日','value':'1d'},
                {label:'7日','value':'7d'},
                {label:'30日','value':'30d'},
                {label:'90日','value':'90d'},
                {label:'全期間','value':'all'},
              ],
              onChange:v=>props.setAttributes({range:v})
            }),
            el(SelectControl, {
              label:'種類',
              value:a.type,
              options:[
                {label:'ドメイン','value':'domain'},
                {label:'ページ','value':'page'},
                {label:'UTM','value':'utm'},
                {label:'入口','value':'dest'},
              ],
              onChange:v=>props.setAttributes({type:v})
            }),
            el(TextControl, {
              label:'件数',
              type:'number',
              value:a.limit,
              onChange:v=>props.setAttributes({limit: parseInt(v||'50',10)})
            })
          )
        ),
        el('p', {}, '逆アクセスランキング（プレビューは公開画面でご確認ください）'),
        el('code', {}, `[cdb_referral_rank range="${a.range}" type="${a.type}" limit="${a.limit}"]`)
      );
    },
    save: function(){ return null; }
  });
})(window.wp);