function findElement(node, predicate) {
  if (!node || typeof node !== 'object') {
    return null;
  }

  if (predicate(node)) {
    return node;
  }

  for (const child of node.children || []) {
    const match = findElement(child, predicate);
    if (match) {
      return match;
    }
  }

  return null;
}

describe('Mediagraph Gutenberg block', () => {
  let block;
  let useBlockProps;

  beforeEach(() => {
    jest.resetModules();

    useBlockProps = jest.fn((props = {}) => props);
    useBlockProps.save = jest.fn((props = {}) => props);

    global.window = {
      wp: {
        blocks: {
          registerBlockType: jest.fn((_name, settings) => {
            block = settings;
          }),
        },
        components: {
          Button: 'Button',
          ToolbarButton: 'ToolbarButton',
          ToolbarGroup: 'ToolbarGroup',
          PanelBody: 'PanelBody',
          TextControl: 'TextControl',
          TextareaControl: 'TextareaControl',
          SelectControl: 'SelectControl',
        },
        blockEditor: {
          useBlockProps,
          BlockControls: 'BlockControls',
          InspectorControls: 'InspectorControls',
          RichText: 'RichText',
        },
        element: {
          createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
          useEffect: jest.fn(),
          useRef: (value) => ({ current: value }),
          useState: (value) => [value, jest.fn()],
        },
      },
    };

    require('../gutenberg-block.js');
  });

  afterEach(() => {
    delete global.window;
  });

  function attributes(overrides = {}) {
    return {
      assetId: 42,
      assetUrl: 'https://example.test/original.jpg',
      assetHtml: '<img src="https://example.test/original.jpg" />',
      assetType: 'image',
      attachmentId: 7,
      alignment: 'center',
      linkTo: 'none',
      size: 'medium',
      title: '',
      byline: '',
      headline: '',
      description: '',
      altText: '',
      extendedDescription: '',
      keywords: '',
      usageRights: '',
      ...overrides,
    };
  }

  function renderEdit(setAttributes = jest.fn(), overrides = {}) {
    return {
      tree: block.edit({
        attributes: attributes(overrides),
        setAttributes,
        clientId: 'block-client-id',
      }),
      setAttributes,
    };
  }

  test('stores the numeric Rails asset ID without dropping it on reload', () => {
    expect(block.attributes.assetId).toEqual({ type: 'number' });
  });

  test('passes alignment to both editor and saved block wrappers', () => {
    renderEdit();
    block.save({ attributes: attributes() });

    expect(useBlockProps).toHaveBeenCalledWith({ className: 'aligncenter' });
    expect(useBlockProps.save).toHaveBeenCalledWith({ className: 'aligncenter' });
  });

  test('keeps the previous size when the rendition download fails', async () => {
    window.mediagraphDownloadAsset = jest.fn().mockRejectedValue(new Error('Download failed'));
    window.mediagraphBuildHtml = jest.fn();
    const { tree, setAttributes } = renderEdit();
    const sizeControl = findElement(
      tree,
      (node) => node.type === 'SelectControl' && node.props.label === 'Size'
    );

    sizeControl.props.onChange('large');
    await Promise.resolve();
    await Promise.resolve();

    expect(setAttributes).not.toHaveBeenCalled();
  });

  test('updates the size, URL, attachment, and HTML after a successful download', async () => {
    window.mediagraphDownloadAsset = jest.fn().mockResolvedValue({
      url: 'https://example.test/large.jpg',
      attachmentId: 8,
    });
    window.mediagraphBuildHtml = jest.fn().mockReturnValue('<img src="https://example.test/large.jpg" />');
    const { tree, setAttributes } = renderEdit();
    const sizeControl = findElement(
      tree,
      (node) => node.type === 'SelectControl' && node.props.label === 'Size'
    );

    sizeControl.props.onChange('large');
    await Promise.resolve();
    await Promise.resolve();

    expect(setAttributes).toHaveBeenCalledWith(expect.objectContaining({
      size: 'large',
      assetUrl: 'https://example.test/large.jpg',
      attachmentId: 8,
      assetHtml: '<img src="https://example.test/large.jpg" />',
    }));
  });
});
