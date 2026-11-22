function reservationConfirmation() {
    return {
        type: document.querySelector('[data-confirmation-type]')?.dataset.confirmationType,
        confirmation: null,

        init() {
            try {
                this.confirmation = JSON.parse(localStorage.getItem('customer_confirmation') || 'null');
            } catch (error) {
                localStorage.removeItem('customer_confirmation');
            }

            const expectedStatus = this.type === 'table-reservation' ? 'confirmed' : 'submitted';
            if (
                !this.confirmation
                || this.confirmation.type !== this.type
                || this.confirmation.status !== expectedStatus
                || !Number.isInteger(Number(this.confirmation.tableId))
                || Number(this.confirmation.tableId) < 1
            ) {
                this.clearCustomerFlowState();
                window.location.replace(document.querySelector('[data-confirmation-type]').dataset.selectBookingUrl);
                return;
            }

            this.clearCustomerFlowState();
        },

        clearCustomerFlowState() {
            [
                'customer_confirmation',
                'customer_order_cart',
                'customer_order_table',
                'customer_pending_table_selection',
                'customer_guests_adults',
                'customer_guests_children',
                'customer_booking_type',
                'customer_payment_method',
                'customer_last_submitted_order'
            ].forEach(key => localStorage.removeItem(key));
        }
    };
}
