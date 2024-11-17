function waitForElm(selector, returnFirst) {
  return new Promise((resolve) => {
    if (document.querySelectorAll(selector).length) {
      const results = document.querySelectorAll(selector);
      return resolve(returnFirst ? results[0] : results);
    }

    const observer = new MutationObserver((mutations) => {
      if (document.querySelectorAll(selector).length) {
        observer.disconnect();
        const results = document.querySelectorAll(selector);
        resolve(returnFirst ? results[0] : results);
      }
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });
  });
}

class WIM_Headers {
  constructor() {
    this.headers = null;
    this.inputWrappers = null;
    this.selectedHeader = null;
    this.form = null;

    this.handleHeaderClick = this.handleHeaderClick.bind(this);
    this.handleInputWrapperClick = this.handleInputWrapperClick.bind(this);
    this.init();
  }

  isHeaderActive(header) {
    if (!header.dataset.disabled) return true;
    if (header.dataset.disabled === "true") return false;
    if (header.dataset.disabled === "false") return true;
    return true;
  }

  handleInputWrapperClick(inputWrapper) {
    if (!this.selectedHeader) return;

    this.selectedHeader.dataset.disabled = "true";
    const fieldWrapper = inputWrapper.querySelector(".field-wrapper");
    const input = inputWrapper.querySelector("input");

    fieldWrapper.innerHTML = this.selectedHeader.innerHTML;
    fieldWrapper.dataset.column = this.selectedHeader.innerText;
    input.value = this.selectedHeader.innerText;

    this.selectedHeader = undefined;
  }

  handleHeaderClick(header) {
    if (this.isHeaderActive(header)) {
      this.handleHeaderActiveClick(header);
    } else {
      this.handleHeaderDisabledClick(header);
    }
  }

  handleHeaderActiveClick(header) {
    this.selectedHeader = header;
  }

  handleHeaderDisabledClick(header) {
    this.selectedHeader = undefined;
    header.dataset.disabled = "false";

    const relatedFieldWrapper = document.querySelector(
      `.field-wrapper[data-column='${header.innerText}']`,
    );
    const relatedInput =
      relatedFieldWrapper.parentElement.querySelector("input");

    relatedFieldWrapper.innerHTML = "";
    relatedFieldWrapper.dataset.column = "";
    relatedInput.value = "";
  }

  async init() {
    this.form = await waitForElm("form.product-fields", true);
    this.headers = await waitForElm(".headers-wrapper .header");
    this.inputWrappers = await waitForElm(".input-wrapper");

    this.headers.forEach((header) => {
      let relatedInputWrapper = false;
      this.inputWrappers.forEach((wrapper) => {
        if (relatedInputWrapper) return;
        const fieldWrapper = wrapper.querySelector(
          `[data-id="set_${header.innerText}"],[data-id="${header.innerText}"]`,
        );
        if (fieldWrapper) relatedInputWrapper = wrapper;
      });

      if (relatedInputWrapper) {
        this.handleHeaderActiveClick(header);
        this.handleInputWrapperClick(relatedInputWrapper);
      }

      header.addEventListener("click", () => this.handleHeaderClick(header));
    });

    this.inputWrappers.forEach((inputWrapper) => {
      inputWrapper.addEventListener("click", () =>
        this.handleInputWrapperClick(inputWrapper),
      );
    });
  }
}

new WIM_Headers();
