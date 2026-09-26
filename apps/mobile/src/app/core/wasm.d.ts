/**
 * A .wasm imported with `with { loader: 'file' }` is not a module. The build
 * copies the file into the output and the import is its address there.
 */
declare module '*.wasm' {
  const url: string;
  export default url;
}
