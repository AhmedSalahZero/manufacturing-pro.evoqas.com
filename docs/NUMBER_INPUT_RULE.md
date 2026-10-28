# Rule: number cells in a row have the "three dots" copy button

Any number typed into a row of cells (one cell per year, per month, per quarter) uses `resources/js/Components/CopyInput.vue`.
The three dots next to the number copy it into all the cells after it in the same row. The last cell of a row has no dots.
* Price cells: copying a price makes the following years the same price, with 0 % growth.
* Growth cells: copying a growth % re-prices the following years one after the other.
Single number fields that are not part of a row (for example the opening stock quantity) stay plain inputs.
