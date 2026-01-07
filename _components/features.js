class FeaturesSection extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    this.shadowRoot.innerHTML = `
      <style>
        section {
          padding: 4rem 2rem;
          background: #f7f7f7;
        }

        .grid {
          max-width: 1100px;
          margin: 0 auto;
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
          gap: 2rem;
        }
      </style>

      <section>
        <div class="grid">
          <slot></slot>
        </div>
      </section>
    `;
  }
}

customElements.define('features-section', FeaturesSection);