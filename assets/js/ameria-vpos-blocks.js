(function () {
    if (
        !window.wc ||
        !window.wc.wcBlocksRegistry ||
        !window.wc.wcSettings ||
        !window.wp ||
        !window.wp.element ||
        !window.wp.htmlEntities
    ) {
        return;
    }

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { createElement } = window.wp.element;
    const { decodeEntities } = window.wp.htmlEntities;

    const settings = getSetting('ameria_vpos_data', {});

    const title = decodeEntities(settings.title || 'Credit/Debit Card');
    const description = decodeEntities(
        settings.description || 'Pay securely by card via Ameriabank.'
    );

    const Label = function () {
        return createElement('span', null, title);
    };

    const Content = function () {
        return createElement(
            'div',
            { className: 'ameria-vpos-blocks-content' },
            description
        );
    };

    registerPaymentMethod({
        name: 'ameria_vpos',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () {
            return true;
        },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
