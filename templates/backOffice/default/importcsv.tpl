{extends file="admin-layout.tpl"}

{block name="after-admin-css"}
    {stylesheets file='assets/import-csv.css' source='ImportCSV'}
        <link rel="stylesheet" href="{$asset_url}">
    {/stylesheets}
{/block}

{block name="page-title"}{intl d='importcsv.bo.default' l='Import from CSV'}{/block}

{block name="check-resource"}module.ImportCSV{/block}
{block name="check-access"}update{/block}

{block name="main-content"}
    <div class="modules">
        <div id="wrapper" class="container">
            <div class="clearfix">
                <ul class="breadcrumb pull-left">
                    <li><a href="{url path='/admin/home'}">{intl d='importcsv.bo.default' l="Home"}</a></li>
                    <li><a href="{url path='/admin/modules'}">{intl d='importcsv.bo.default' l="Modules"}</a></li>
                    <li><a href="{url path='/admin/module/ImportCSV'}">{intl d='importcsv.bo.default' l="Import from CSV"}</a></li>
                    <li>{block name="step"}{/block}</li>
                </ul>
            </div>

            <div class="row">
                <div class="col-md-12">

                    <div class="general-block-decorator">
                        {block name="inner-content"}{/block}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/block}
