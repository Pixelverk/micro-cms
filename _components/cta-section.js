class CTASection extends HTMLElement {
  connectedCallback() {
    if (this._rendered) return;
    this._rendered = true;

    const url = this.getAttribute('url') || '#';
    const linktext = this.getAttribute('linktext') || 'Click here';
    const title = this.getAttribute('title') || 'Default Title';
    const text = this.getAttribute('text') || 'Default text';

    this.innerHTML = `
      <style>
        cta-section {
          display: block;
          background: #e0f7fa;
        }

        cta-section .cta {
          padding: 3rem 2rem;
          text-align: center;
        }

        cta-section .cta h1 {
          font-size: 2rem;
          margin-bottom: 1rem;
        }

        cta-section .cta p {
          font-size: 1.2rem;
          margin-bottom: 1.5rem;
        }

        cta-section .cta-button {
          display: inline-block;
          padding: 0.75rem 1.5rem;
          background: #00796b;
          color: #fff;
          text-decoration: none;
          border-radius: 4px;
        }
      </style>

      <div class="cta">
        <h1>${title}</h1>
        <p>${text}</p>
        <a href="${url}" class="cta-button">${linktext}</a>
      </div>
    `;
  }

  static get observedAttributes() {
    return ["url", "linktext", "title", "text"];
  }

  attributeChangedCallback() {
    this._rendered = false;
    this.connectedCallback();
  }
}

customElements.define('cta-section', CTASection);