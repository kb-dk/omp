{**
 * plugins/generic/RDLSolrSearch/templates/settings.tpl
 *
 * Copyright (c) 2026 Royal Danish Library
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings form for the Solr connection, with the result of a connection test.
 *}
<script>
	$(function() {ldelim}
		$('#rdlSolrSearchSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="rdlSolrSearchSettings"
	method="POST"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}

	{fbvFormArea id="rdlSolrSearchConnectionStatus" title="plugins.generic.rdlSolrSearch.settings.status"}
		{if $connection.ok}
			<p>
				{translate key="plugins.generic.rdlSolrSearch.settings.status.ok"
					core=$connectionCore|escape
					version=$connection.solrVersion|default:"?"|escape
					numDocs=$connection.numDocs|escape}
			</p>
		{else}
			<p class="pkp_form_error">
				{translate key="plugins.generic.rdlSolrSearch.settings.status.failed"
					core=$connectionCore|escape
					error=$connection.error|escape}
			</p>
		{/if}
		<p>{translate key="plugins.generic.rdlSolrSearch.settings.status.description"}</p>
	{/fbvFormArea}

	{fbvFormArea id="rdlSolrSearchConnection" title="plugins.generic.rdlSolrSearch.settings.connection"}
		{fbvFormSection}
			{fbvElement type="text" id="solrUrl" value=$solrUrl required=true
				label="plugins.generic.rdlSolrSearch.settings.solrUrl"
				description="plugins.generic.rdlSolrSearch.settings.solrUrl.description"}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="solrCore" value=$solrCore required=true size=$fbvStyles.size.SMALL
				label="plugins.generic.rdlSolrSearch.settings.solrCore"}
		{/fbvFormSection}
		{fbvFormSection}
			{fbvElement type="text" id="solrTimeout" value=$solrTimeout required=true size=$fbvStyles.size.SMALL
				label="plugins.generic.rdlSolrSearch.settings.solrTimeout"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
