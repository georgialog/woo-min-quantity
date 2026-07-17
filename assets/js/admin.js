jQuery(document).ready(function ($) {
    // Count existing rows to generate unique indexes for new rows
    let rowIndex = $('#woo-min-qty-rules-body .woo-min-qty-rule-row').length;

    // Add a new rule row by cloning the hidden template
    $('#woo-min-qty-add-rule').on('click', function () {
        const $template = $('#woo-min-qty-row-template .woo-min-qty-rule-row').first();
        if (!$template.length) return;

        const $newRow = $template.clone();

        // Replace the __INDEX__ placeholder with the real sequential index
        $newRow.find('input, select').each(function () {
            const name = $(this).attr('name') || '';
            $(this).attr('name', name.replace(/__INDEX__/g, rowIndex));
        });

        // Reset values in the cloned row
        $newRow.find('input[type="number"]').val('');
        $newRow.find('input[type="text"]').val('');
        $newRow.find('select').val('');

        $('#woo-min-qty-rules-body .woo-min-qty-no-rules').remove();
        $('#woo-min-qty-rules-body').append($newRow);

        rowIndex++;
    });

    // Remove a rule row
    $(document).on('click', '.woo-min-qty-remove-rule', function () {
        $(this).closest('tr').remove();

        if ($('#woo-min-qty-rules-body .woo-min-qty-rule-row').length === 0) {
            $('#woo-min-qty-rules-body').html(
                '<tr class="woo-min-qty-no-rules">' +
                '<td colspan="4" class="no-rules-placeholder">' +
                'No rules yet &mdash; click <strong>Add Rule</strong> to get started.' +
                '</td></tr>'
            );
        }
    });

    // Warn before leaving with unsaved changes
    let formDirty = false;
    $('#woo-min-qty-form').on('change input', function () { formDirty = true; });
    $('#woo-min-qty-form').on('submit', function () { formDirty = false; });
    $(window).on('beforeunload', function () {
        if (formDirty) return true;
    });
});
