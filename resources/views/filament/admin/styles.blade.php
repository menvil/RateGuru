<style>
    /*
     * Checkboxes, radios, selects and file pickers are pressed like buttons,
     * and show the pointer like them; Filament leaves them on the arrow.
     */
    input[type='checkbox']:not(:disabled),
    input[type='radio']:not(:disabled),
    select:not(:disabled),
    input[type='file']:not(:disabled)::file-selector-button {
        cursor: pointer;
    }

    /*
     * The records-per-page chooser under every table is a plain control, not a
     * form field: no frame around it, and no focus ring left on it after a
     * value is picked. (A select matches :focus-visible on a mouse pick too,
     * so the ring cannot be kept for the keyboard alone.)
     */
    .fi-pagination-records-per-page-select .fi-input-wrp,
    .fi-pagination-records-per-page-select .fi-input-wrp:focus-within {
        box-shadow: none;
        background-color: transparent;
    }

    .fi-pagination-records-per-page-select .fi-input-wrp-prefix {
        border-inline-end-width: 0;
    }

    .fi-pagination-records-per-page-select select,
    .fi-pagination-records-per-page-select select:focus,
    .fi-pagination-records-per-page-select select:focus-visible {
        outline: none;
        box-shadow: none;
        cursor: pointer;
    }
</style>
