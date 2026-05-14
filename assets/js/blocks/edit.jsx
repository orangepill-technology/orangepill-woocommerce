/**
 * OrangepillEdit — non-interactive preview rendered in the WordPress block editor.
 *
 * Displayed when a merchant inserts the Checkout block into a page and inspects it.
 * Must not trigger AJAX or attempt to load real payment options.
 */

export function OrangepillEdit() {
    return (
        <div className="orangepill-blocks-edit-preview">
            <p style={ { margin: 0, fontWeight: 600 } }>Orangepill</p>
            <p style={ { margin: '4px 0 0', color: '#666', fontSize: '12px' } }>
                Payment method options will appear here for the customer.
            </p>
        </div>
    );
}
