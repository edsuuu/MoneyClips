import { Router } from 'express';

import { asyncHandler } from '@/Http/Helpers/AsyncHandler';
import { PostQueueService } from '@/Services/PostQueueService';
import { SessionService } from '@/Services/SessionService';

import { AuthController } from '../Controllers/AuthController';
import { DocsController } from '../Controllers/DocsController';
import { HealthController } from '../Controllers/HealthController';
import { PostController } from '../Controllers/PostController';
import { videoUpload } from '../Middleware/VideoUpload';

export class Routers {
    private readonly router: Router = Router();
    private readonly docsController: DocsController;
    private readonly healthController: HealthController;
    private readonly authController: AuthController;
    private readonly postController: PostController;

    public constructor(
        postQueue: PostQueueService = new PostQueueService(),
        sessionService: SessionService = new SessionService(),
    ) {
        this.docsController = new DocsController();
        this.healthController = new HealthController();
        this.postController = new PostController(postQueue);
        this.authController = new AuthController(sessionService);
        this.initializeRoutes();
    }

    private initializeRoutes(): void {
        this.router.get(
            '/',
            asyncHandler((req, res) => this.docsController.index(req, res)),
        );

        this.router.get('/health', (req, res) => this.healthController.health(req, res));

        this.router.post(
            '/session',
            asyncHandler((req, res) => this.authController.session(req, res)),
        );
        this.router.post(
            '/login',
            asyncHandler((req, res) => this.authController.login(req, res)),
        );

        this.router.post(
            '/posts',
            videoUpload,
            asyncHandler((req, res) => this.postController.createPost(req, res)),
        );
    }

    public getRouter(): Router {
        return this.router;
    }
}
