export class BaseComponent extends HTMLElement {
    connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    // 1️⃣ Styles
    if (this.styles) {
        const style = document.createElement('style');
        style.textContent = this.styles;
        this.appendChild(style);
    }

    // 2️⃣ Wrapper
    let wrapper;
    if (this.wrapperTag) {
        wrapper = document.createElement(this.wrapperTag);
        if (this.wrapperClass) wrapper.className = this.wrapperClass;

        // Move all children except STYLE into wrapper
        const toMove = Array.from(this.childNodes).filter(
        node => node.nodeName !== 'STYLE'
        );
        toMove.forEach(node => wrapper.appendChild(node));

        this.appendChild(wrapper);
    }

    // 3️⃣ Pass wrapper to render() so content goes inside
    if (typeof this.render === 'function') {
        this.render(wrapper || this);
    }
    }
}
