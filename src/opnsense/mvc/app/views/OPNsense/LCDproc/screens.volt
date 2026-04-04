{#
    Copyright (C) 2024 OPNsense LCDproc Plugin
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
#}

<script>
    $( document ).ready(function() {
        var data_get_map = {'frm_screens_settings':"/api/lcdproc/general/get"};
        mapDataToFormUI(data_get_map).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            updateServiceControlUI('lcdproc');
        });

        // Show/hide traffic interface selector
        function toggleTrafficOptions() {
            var traffic = $('#screens\\.scr_traffic').is(':checked');
            if (traffic) {
                $('[id="row_screens.scr_traffic_interface"]').closest('tr').show();
            } else {
                $('[id="row_screens.scr_traffic_interface"]').closest('tr').hide();
            }
        }

        // Show/hide temperature unit selector
        function toggleTempOptions() {
            var temp = $('#screens\\.scr_cputemperature').is(':checked');
            if (temp) {
                $('[id="row_screens.scr_cputemperature_unit"]').closest('tr').show();
            } else {
                $('[id="row_screens.scr_cputemperature_unit"]').closest('tr').hide();
            }
        }

        // Show/hide traffic by address options
        function toggleTrafficByAddressOptions() {
            var tba = $('#screens\\.scr_traffic_by_address').is(':checked');
            var rows = [
                'row_screens.scr_traffic_by_address_if',
                'row_screens.scr_traffic_by_address_sort',
                'row_screens.scr_traffic_by_address_filter',
                'row_screens.scr_traffic_by_address_hostipformat'
            ];
            rows.forEach(function(row) {
                if (tba) {
                    $('[id="' + row + '"]').closest('tr').show();
                } else {
                    $('[id="' + row + '"]').closest('tr').hide();
                }
            });
        }

        $(document).on('change', '#screens\\.scr_traffic', toggleTrafficOptions);
        $(document).on('change', '#screens\\.scr_cputemperature', toggleTempOptions);
        $(document).on('change', '#screens\\.scr_traffic_by_address', toggleTrafficByAddressOptions);

        // Initial toggle after form load
        setTimeout(function() {
            toggleTrafficOptions();
            toggleTempOptions();
            toggleTrafficByAddressOptions();
        }, 500);

        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/lcdproc/general/set", 'frm_screens_settings',
                    function () { dfObj.resolve(); }, true,
                    function () { dfObj.reject(); }
                );
                return dfObj;
            },
            onAction: function(data, status) {
                updateServiceControlUI('lcdproc');
            }
        });
    });
</script>

<div class="content-box" style="padding-bottom: 1.5em;">
    {{ partial("layout_partials/base_form",['fields':screensForm,'id':'frm_screens_settings'])}}
</div>

{{ partial(
    'layout_partials/base_apply_button',
    {
        'data_endpoint': '/api/lcdproc/service/reconfigure',
        'data_service_widget': 'lcdproc',
        'data_change_message_content': lang._('Apply will reload the LCDproc service. Any unsaved changes will be lost.')
    }
) }}
