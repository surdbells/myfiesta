/**
 * A .wasm imported with `with { loader: 'file' }` is not a module. The build
 * copies the file into the output and the import is its address there.
 *
 * Referenced from camera.ts rather than left for each app to declare: the apps
 * compile this package as source, and a declaration that lived in one of them
 * would leave the other unable to compile the import at all.
 */
declare module '*.wasm' {
  const url: string;
  export default url;
}
